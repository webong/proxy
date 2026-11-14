#!/usr/bin/env python3
"""Keep standalone Go tests byte-identical to the canonical shared fixtures."""
from pathlib import Path
import argparse
import shutil


def require_real_directory(path, root):
    """Reject linked ancestors before reading or writing managed fixtures."""
    for node in [path, *path.parents]:
        if node == root.parent:
            break
        if node.is_symlink() or (node.exists() and not node.is_dir()):
            raise ValueError(f'Fixture directory must be a real directory: {node}')


def fixture_sources(root):
    for area in ['fixtures', 'tcp', 'udp']:
        for category in ['valid', 'invalid']:
            group = root / 'spec' / area / category
            require_real_directory(group, root)
            if not group.is_dir() or not any(group.rglob('*.json')):
                raise ValueError(f'Missing or empty canonical fixture group: {group}')
    tls = root / 'tests/fixtures/tls'
    require_real_directory(tls, root)
    required = [root / 'spec' / name for name in [
        'fixtures/bypass.json', 'http-credential-cases.json', 'http-connect-cases.json',
        'socks5-cases.json', 'udp-datagrams.json', 'api/go-v0.3.json']]
    required += [tls / name for name in ['ca.pem', 'proxy.pem', 'proxy-key.pem', 'expired.pem', 'expired-key.pem']]
    files = {}
    for pattern in ['fixtures/**/*.json', 'tcp/**/*.json', 'udp/**/*.json',
                    'http-credential-cases.json', 'http-connect-cases.json',
                    'socks5-cases.json', 'udp-datagrams.json', 'api/go-v0.3.json']:
        for source in (root / 'spec').glob(pattern):
            files[Path('spec') / source.relative_to(root / 'spec')] = source
    for source in tls.iterdir():
        if source.is_file():
            files[Path('tls') / source.name] = source
    # Validate the complete input before sync can delete or overwrite any mirror.
    for source in [*required, *files.values()]:
        require_real_directory(source.parent, root)
        if source.is_symlink() or not source.is_file():
            raise ValueError(f'Canonical fixture must be a real file: {source}')
    return files


def synchronize(root, check):
    files = fixture_sources(root)
    target = root / 'go/testdata'
    for path in [target / 'spec', target / 'tls', *((target / p).parent for p in files)]:
        require_real_directory(path, root)
    actual = {p.relative_to(target) for folder in ['spec', 'tls']
              for p in (target / folder).rglob('*') if p.is_file() or p.is_symlink()}
    changed = [path for path, source in files.items()
               if (target / path).is_symlink() or not (target / path).is_file()
               or (target / path).read_bytes() != source.read_bytes()]
    extra = actual - files.keys()
    if check:
        if changed or extra:
            raise ValueError('Go testdata differs from canonical fixtures; run python3 scripts/sync_go_testdata.py\n'
                             + '\n'.join(str(p) for p in sorted(set(changed) | extra)))
        print(f'Go testdata matches {len(files)} canonical files')
        return
    for path in changed:
        destination = target / path
        destination.parent.mkdir(parents=True, exist_ok=True)
        if destination.is_symlink():
            destination.unlink()  # Replace the link itself; never write through it.
        shutil.copyfile(files[path], destination)
    for path in extra:
        (target / path).unlink()  # Includes stale links, without traversing targets.
    print(f'Synchronized {len(files)} Go testdata files')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='fail instead of updating mirrors')
    args = parser.parse_args()
    try:
        synchronize(Path(__file__).resolve().parent.parent, args.check)
    except (OSError, ValueError) as error:
        raise SystemExit(str(error)) from error


if __name__ == '__main__':
    main()
