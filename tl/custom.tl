p_q_inner_data#83c95aec pq:string p:string q:string nonce:int128 server_nonce:int128 new_nonce:int256 = P_Q_inner_data;
ping#7abe77ec ping_id:long = Pong;
msg_container#73f1f8dc messages:Vector<Message> = MessageContainer;
message#5bb8e511 msg_id:long seqno:int bytes:int body:bytes = Message;
rpc_result#f35c6d01 req_msg_id:long result:Object = RpcResult;
