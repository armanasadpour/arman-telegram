"""Build a complete cPanel update; --next advances patch 0..50 then minor."""
from pathlib import Path
import argparse, datetime, hashlib, json, re, zipfile

def next_version(version):
    major, minor, patch = map(int, version.split('.'))
    return f'{major}.{minor + 1}.0' if patch >= 50 else f'{major}.{minor}.{patch + 1}'

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--next', action='store_true')
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    version_file = root / 'lib/version.php'
    source = version_file.read_text()
    old = re.search(r"const APP_VERSION = '([^']+)';", source)[1]
    version = next_version(old) if args.next else old
    assert re.fullmatch(r'\d+\.\d+\.\d+', version)
    if args.next:
        fa = version.translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))
        source = source.replace(f"APP_VERSION = '{old}'", f"APP_VERSION = '{version}'")
        source = re.sub(r"const APP_VERSION_FA = '[^']+';", f"const APP_VERSION_FA = '{fa}';", source)
        version_file.write_text(source)
        for name in ['sw.js', 'offline.html']:
            path = root / name
            path.write_text(path.read_text().replace(old, version))
    files = {}
    for path in sorted(root.rglob('*')):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if rel in ['release.json','config.php','install.lock'] or any(p in ['.git','node_modules','__pycache__'] for p in path.parts):
            continue
        if path.suffix in ['.zip','.pyc','.log','.bak']:
            continue
        if rel.startswith(('private/','uploads/')) and path.name not in ['.htaccess','.gitkeep','index.php']:
            raise RuntimeError('Unexpected runtime data: ' + rel)
        files[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
    manifest = {'app':'nobatyar','version':version,'built':datetime.date.today().isoformat(),'files':files}
    (root/'release.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    output = args.output or root.parent/f'nobatyar-cpanel-{version}.zip'
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for rel in [*files,'release.json']:
            info = zipfile.ZipInfo('nobatyar-cpanel/'+rel)
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info,(root/rel).read_bytes())
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None
        for rel, expected in files.items():
            assert hashlib.sha256(archive.read('nobatyar-cpanel/'+rel)).hexdigest() == expected
    print(json.dumps({'file':str(output),'version':version,'files':len(files),'bytes':output.stat().st_size}))

if __name__ == '__main__':
    assert next_version('1.0.0') == '1.0.1'
    assert next_version('1.0.49') == '1.0.50'
    assert next_version('1.0.50') == '1.1.0'
    main()
