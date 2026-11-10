#!/usr/bin/env python3
"""
Simple offline UDP NTP responder for testing and laboratory environments.
Zero external dependencies, works offline on RHEL 9.4.
"""
import socket
import struct
import time
import argparse

NTP_DELTA = 2208988800  # 1970 - 1900 in seconds

def run_server(host, port, offset_ms, stratum, leap, refid):
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind((host, port))
    print(f"ICCT LAB NTP responder listening on {host}:{port}; offset={offset_ms:.1f}ms stratum={stratum} leap={leap}")
    
    offset_sec = offset_ms / 1000.0

    while True:
        try:
            data, addr = sock.recvfrom(1024)
            if len(data) < 48:
                continue
            
            recv_time = time.time() + NTP_DELTA + offset_sec
            rx_sec = int(recv_time)
            rx_frac = int((recv_time - rx_sec) * (2**32))

            # Transmit time from client becomes originate time
            orig_sec, orig_frac = struct.unpack("!II", data[40:48])

            # Prepare response header
            li = (leap & 0x03) << 6
            vn = 4 << 3
            mode = 4  # Server
            byte0 = li | vn | mode

            poll = data[2]
            precision = -18

            root_delay = 0
            root_disp = 100

            ref_bytes = refid.encode('ascii')[:4].ljust(4, b'\0')

            tx_time = time.time() + NTP_DELTA + offset_sec
            tx_sec = int(tx_time)
            tx_frac = int((tx_time - tx_sec) * (2**32))

            pkt = struct.pack(
                "!BBbbII4sIIIIII",
                byte0, stratum, poll, precision,
                root_delay, root_disp, ref_bytes,
                tx_sec, tx_frac,   # reference
                orig_sec, orig_frac, # originate
                rx_sec, rx_frac,   # receive
                tx_sec, tx_frac    # transmit
            )
            sock.sendto(pkt, addr)
        except Exception as e:
            print(f"Error handling request: {e}")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Lab NTP Test Server")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=9123)
    parser.add_argument("--offset-ms", type=float, default=0.0)
    parser.add_argument("--stratum", type=int, default=2)
    parser.add_argument("--leap", type=int, default=0)
    parser.add_argument("--refid", default="GPS")
    args = parser.parse_args()

    run_server(args.host, args.port, args.offset_ms, args.stratum, args.leap, args.refid)

