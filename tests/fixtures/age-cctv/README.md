# Test vectors of the age format

These files are copied from the age test vectors of the C2SP project, <https://github.com/C2SP/CCTV/tree/main/age>
(directory `testdata`; their licence, ISC, allows using them without attribution). Armored files, the hybrid
(post-quantum) recipient and plugins are left out: ThreadSafe implements the X25519 and the passphrase (`scrypt`) recipients
and no armor. `tests/Unit/Crypto/AgeCctvTest.php` runs every file through `AgeReader`.
