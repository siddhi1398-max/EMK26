import socket

server = socket.socket()
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind(("127.0.0.1", 2526))
server.listen(1)
connection, _ = server.accept()
stream = connection.makefile("rwb", buffering=0)
stream.write(b"220 localhost test SMTP\r\n")
data_mode = False
for raw in stream:
    line = raw.rstrip(b"\r\n")
    if data_mode:
        if line == b".":
            data_mode = False
            stream.write(b"250 queued\r\n")
        continue
    command = line.upper()
    if command.startswith(b"EHLO"):
        stream.write(b"250-localhost\r\n250 AUTH LOGIN\r\n")
    elif command == b"AUTH LOGIN" or command == b"DXNLCKBLEGFTCGXLLMNVBQ==":
        stream.write(b"334 VXNlcm5hbWU6\r\n" if command == b"AUTH LOGIN" else b"334 UGFzc3dvcmQ6\r\n")
    elif command == b"YXBWLXBHC3N3B3JK":
        stream.write(b"235 authenticated\r\n")
    elif command.startswith(b"MAIL FROM") or command.startswith(b"RCPT TO"):
        stream.write(b"250 ok\r\n")
    elif command == b"DATA":
        data_mode = True
        stream.write(b"354 end with dot\r\n")
    elif command == b"QUIT":
        stream.write(b"221 bye\r\n")
        break
    else:
        stream.write(b"500 unexpected\r\n")
stream.close()
connection.close()
server.close()
