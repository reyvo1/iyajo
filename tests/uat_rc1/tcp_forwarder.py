#!/usr/bin/env python3
"""Minimal TCP forwarder used by the two-node UAT runner.

The runner keeps a public node port stable across an outage and recovery while the
PHP backend restarts on a fresh port, which is what socat's reuseaddr,fork listener
provided. Depending on socat means an extra system package for one behaviour, so
this does the same job with the standard library.

Usage: tcp_forwarder.py <listen_port> <target_port> [--log FILE]
"""
import argparse
import socket
import socketserver
import sys
import threading

LOG_PATH = None


def _log(message: str) -> None:
    if LOG_PATH:
        with open(LOG_PATH, 'a', encoding='utf-8') as handle:
            handle.write(message + '\n')


class Handler(socketserver.BaseRequestHandler):
    target_port = None

    def handle(self):
        try:
            upstream = socket.create_connection(('127.0.0.1', self.target_port), timeout=10)
        except OSError as exc:
            _log('upstream connect failed: %s' % exc)
            return
        self.request.settimeout(None)
        upstream.settimeout(None)

        def pump(source, sink):
            try:
                while True:
                    chunk = source.recv(65536)
                    if not chunk:
                        break
                    sink.sendall(chunk)
            except OSError:
                pass
            finally:
                for handle_ in (source, sink):
                    try:
                        handle_.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass

        threads = [
            threading.Thread(target=pump, args=(self.request, upstream), daemon=True),
            threading.Thread(target=pump, args=(upstream, self.request), daemon=True),
        ]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def main() -> int:
    global LOG_PATH
    parser = argparse.ArgumentParser()
    parser.add_argument('listen_port', type=int)
    parser.add_argument('target_port', type=int)
    parser.add_argument('--log', default=None)
    args = parser.parse_args()

    LOG_PATH = args.log
    Handler.target_port = args.target_port
    try:
        server = Server(('127.0.0.1', args.listen_port), Handler)
    except OSError as exc:
        _log('listen failed on %d: %s' % (args.listen_port, exc))
        return 1
    _log('forwarding 127.0.0.1:%d -> 127.0.0.1:%d' % (args.listen_port, args.target_port))
    try:
        server.serve_forever(poll_interval=0.2)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
