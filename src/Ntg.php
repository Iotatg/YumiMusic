<?php

declare(strict_types=1);

/**
 * ntgcalls 3.0.4 FFI binding (synchronous C ABI) — audio via shell/ffmpeg
 * source, video via external frame pusher. Structs mirror include/ntgcalls.h.
 */
final class Ntg
{
    public const STREAM_CAPTURE = 0;
    public const SOURCE_FILE = 1;
    public const SOURCE_SHELL = 2;
    public const SOURCE_FFMPEG = 3;
    public const SOURCE_EXTERNAL = 6;
    public const DEVICE_CAMERA = 2;

    public const LIB_PATH = '/opt/ntgcalls/lib/libntgcalls.so';

    private static ?FFI $ffi = null;
    private FFI\CData $handle;

    public function __construct(string $libPath = '/opt/ntgcalls/lib/libntgcalls.so')
    {
        $ffi = self::lib($libPath);
        $this->handle = $ffi->ntg_instance_create();
        if (FFI::isNull($this->handle)) {
            throw new RuntimeException('ntgcalls: instance_create failed');
        }
    }

    public static function lib(string $path): FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        self::$ffi = FFI::cdef(
            'typedef struct { uint8_t* data; size_t len; } ntg_bytes;
             typedef struct { int64_t absolute_capture_timestamp_ms; int rotation; uint16_t width; uint16_t height; } ntg_frame_data;
             typedef struct { int media_source; uint32_t sample_rate; uint8_t channel_count; char* input; bool keep_open; } ntg_audio_description;
             typedef struct { int media_source; int16_t width; int16_t height; uint8_t fps; char* input; bool keep_open; } ntg_video_description;
             typedef struct { ntg_audio_description* microphone; ntg_audio_description* speaker; ntg_video_description* camera; ntg_video_description* screen; } ntg_media_description;
             typedef struct { bool muted; bool video_paused; bool video_stopped; bool presentation_paused; bool presentation_stopped; } ntg_media_state;
             typedef struct ntg_instance ntg_instance;
             void* ntg_instance_create();
             void ntg_instance_destroy(void* handle);
             int ntg_create_call(ntg_instance* handle, int64_t chat_id, char** out);
             int ntg_connect(ntg_instance* handle, int64_t chat_id, const char* params, bool is_presentation);
             int ntg_set_stream_sources(ntg_instance* handle, int64_t chat_id, int mode, ntg_media_description media);
             int ntg_pause(ntg_instance* handle, int64_t chat_id, bool* out);
             int ntg_resume(ntg_instance* handle, int64_t chat_id, bool* out);
             int ntg_mute(ntg_instance* handle, int64_t chat_id, bool* out);
             int ntg_unmute(ntg_instance* handle, int64_t chat_id, bool* out);
             int ntg_stop(ntg_instance* handle, int64_t chat_id);
             int ntg_time(ntg_instance* handle, int64_t chat_id, int mode, uint64_t* out);
             int ntg_get_state(ntg_instance* handle, int64_t chat_id, ntg_media_state* out);
             int ntg_send_external_frame(ntg_instance* handle, int64_t chat_id, int device, const uint8_t* data, size_t data_len, ntg_frame_data frame_data);
             void ntg_string_free(char* value);
             const char* ntg_last_error();
             const char* ntg_get_version();',
            $path
        );
        return self::$ffi;
    }

    public function version(): string
    {
        return self::lib(self::LIB_PATH)->ntg_get_version();
    }

    private static function cstr(string $s, array &$keep): FFI\CData
    {
        $ffi = self::ffi();
        $m = $ffi->new('char[' . (strlen($s) + 1) . ']');
        FFI::memcpy($m, $s, strlen($s));
        $keep[] = $m;
        return $ffi->cast('char*', $m);
    }

    private static function ffi(): FFI
    {
        return self::$ffi ?? throw new RuntimeException('ntgcalls: not loaded');
    }

    /** @return string SDP offer (JSON) */
    public function create(int $chatId): string
    {
        $ffi = self::ffi();
        $out = $ffi->new('char*');
        $rc = $ffi->ntg_create_call($this->handle, $chatId, FFI::addr($out));
        if ($rc !== 0) {
            throw new RuntimeException("ntg_create_call rc=$rc: " . $ffi->ntg_last_error());
        }
        if (FFI::isNull($out)) {
            return '';
        }
        $sdp = FFI::string($out);
        $ffi->ntg_string_free($out);
        return $sdp;
    }

    /** Audio = shell source (lib shells out to ffmpeg), video optional external. */
    public function setStreamSources(int $chatId, ?string $audioPath, ?array $cam = null): void
    {
        $ffi = self::ffi();
        $keep = [];
        $mic = null;
        if ($audioPath !== null) {
            $mic = $ffi->new('ntg_audio_description');
            $mic->media_source = self::SOURCE_SHELL;
            $mic->sample_rate = 96000;
            $mic->channel_count = 2;
            $mic->input = self::cstr($audioPath, $keep);
            $mic->keep_open = false;
        }
        $camera = null;
        if ($cam !== null) {
            $camera = $ffi->new('ntg_video_description');
            $camera->media_source = self::SOURCE_EXTERNAL; // frames pushed by us
            $camera->width = $cam['width'];
            $camera->height = $cam['height'];
            $camera->fps = $cam['fps'];
            $camera->input = null;
            $camera->keep_open = false;
        }
        $d = $ffi->new('ntg_media_description');
        $d->microphone = $mic === null ? null : FFI::addr($mic);
        $d->speaker = null;
        $d->camera = $camera === null ? null : FFI::addr($camera);
        $d->screen = null;
        $rc = $ffi->ntg_set_stream_sources($this->handle, $chatId, self::STREAM_CAPTURE, $d);
        if ($rc !== 0) {
            throw new RuntimeException("ntg_set_stream_sources rc=$rc: " . $ffi->ntg_last_error());
        }
    }

    public function connect(int $chatId, string $params): void
    {
        $ffi = self::ffi();
        $keep = [];
        $p = self::cstr($params, $keep);
        $rc = $ffi->ntg_connect($this->handle, $chatId, $p, false);
        if ($rc !== 0) {
            throw new RuntimeException("ntg_connect rc=$rc: " . $ffi->ntg_last_error());
        }
    }

    private static function boolOut(int $rc, FFI $ffi, FFI\CData $out): bool
    {
        return $rc === 0 && !FFI::isNull($out) && $out->cdata;
    }

    public function pause(int $chatId): bool
    {
        $ffi = self::ffi();
        $out = $ffi->new('bool');
        return self::boolOut($ffi->ntg_pause($this->handle, $chatId, FFI::addr($out)), $ffi, $out);
    }

    public function resume(int $chatId): bool
    {
        $ffi = self::ffi();
        $out = $ffi->new('bool');
        return self::boolOut($ffi->ntg_resume($this->handle, $chatId, FFI::addr($out)), $ffi, $out);
    }

    public function mute(int $chatId): bool
    {
        $ffi = self::ffi();
        $out = $ffi->new('bool');
        return self::boolOut($ffi->ntg_mute($this->handle, $chatId, FFI::addr($out)), $ffi, $out);
    }

    public function unmute(int $chatId): bool
    {
        $ffi = self::ffi();
        $out = $ffi->new('bool');
        return self::boolOut($ffi->ntg_unmute($this->handle, $chatId, FFI::addr($out)), $ffi, $out);
    }

    public function stop(int $chatId): bool
    {
        return self::ffi()->ntg_stop($this->handle, $chatId) === 0;
    }

    /** Playback position in seconds (capture mode). */
    public function time(int $chatId): int
    {
        $ffi = self::ffi();
        $out = $ffi->new('uint64_t');
        $rc = $ffi->ntg_time($this->handle, $chatId, self::STREAM_CAPTURE, FFI::addr($out));
        return $rc === 0 ? (int) $out->cdata : 0;
    }

    /** @return array{muted:bool,videoPaused:bool,videoStopped:bool}|null */
    public function state(int $chatId): ?array
    {
        $ffi = self::ffi();
        $st = $ffi->new('ntg_media_state');
        $rc = $ffi->ntg_get_state($this->handle, $chatId, FFI::addr($st));
        if ($rc !== 0) {
            return null;
        }
        return [
            'muted' => (bool) $st->muted,
            'videoPaused' => (bool) $st->video_paused,
            'videoStopped' => (bool) $st->video_stopped,
        ];
    }

    public function sendExternalFrame(int $chatId, string $frame, int $tsMs, int $w, int $h): int
    {
        $ffi = self::ffi();
        $len = strlen($frame);
        $buf = $ffi->new("unsigned char[$len]");
        FFI::memcpy($buf, $frame, $len);
        $fd = $ffi->new('ntg_frame_data');
        $fd->absolute_capture_timestamp_ms = $tsMs;
        $fd->rotation = 0;
        $fd->width = $w;
        $fd->height = $h;
        return $ffi->ntg_send_external_frame($this->handle, $chatId, self::DEVICE_CAMERA, $buf, $len, $fd);
    }

    public function close(): void
    {
        self::ffi()->ntg_instance_destroy(FFI::cast('void*', $this->handle));
    }
}
