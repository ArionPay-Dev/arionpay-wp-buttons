"""Build the WordPress payment-buttons plugin zip (copies the ArionPay PHP SDK in first).
   python3 build.py  ->  dist/arionpay-payment-buttons.zip  (Plugins > Add New > Upload Plugin)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
PLUGIN = HERE / "arionpay-payment-buttons"
lib = PLUGIN / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
guard = "<?php\n// Silence is golden.\n"
for d in (PLUGIN, PLUGIN / "lib", lib, lib / "src"):
    (d / "index.php").write_text(guard)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "arionpay-payment-buttons.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(PLUGIN):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, "arionpay-payment-buttons/" + str(p.relative_to(PLUGIN)))
print(out, os.path.getsize(out), "bytes")
