# Encrypted backups

A backup of a shop holds customer data. ThreadSafe can encrypt its archives so that **the storage directory, a download, a copy on another server or a lost USB stick give nothing away without a key that the server does not have**. The format is [age](https://age-encryption.org/) (version 1), written and read by ThreadSafe itself in plain PHP, so the real `age` tool opens the files too. Commands are in [BACKUP.md](BACKUP.md) (4.7 `keys`), restoring in [RESTORE.md](RESTORE.md) section 12, the pages in [WEB.md](WEB.md); the design and its reasons are in [PHASE5.md](PHASE5.md) 3.5.

> **If the key is lost, the backups are lost.** Nobody can open them - not the server, not the author of this program. Store the secret key (or the passphrase) somewhere other than the server that makes the backups, before you rely on a backup.

## 1. Quick start

```bash
# 1. make a key pair; the secret key goes to a file (mode 0600) that you take away from this server
php bin/threadsafe keys generate --output=/safe/place/shop-key.txt
#    prints the public key:  age1...   (the line "# public key:" of the file says it too)

# 2. tell the profile to encrypt for the public key
php bin/threadsafe profile edit shop --recipient=age1...

# 3. back up as usual (by hand or by the schedule); the parts are <id>[-pNN].zip.age
php bin/threadsafe backup shop

# 4. check with the key, restore with the key
php bin/threadsafe verify shop --identity-file=/safe/place/shop-key.txt
php threadsafe-restore.php restore shop_..._p01.zip.age --root=DIR --identity-file=/safe/place/shop-key.txt ...
```

In the browser: *Profiles* → the site → **Keys** → *Make a key pair* (section 3), or paste public keys into the *Encryption* section of the profile form.

## 2. What is protected, and what is not

**Protected:** the content of every archive part - the files of the site, the database dumps, the manifest inside (domains, paths, table names, module list, warnings with file names). Every 64 KiB block is encrypted and authenticated (ChaCha20-Poly1305): a part that was changed, cut or extended is found, not restored wrongly.

**Left readable in the storage** (decision of the plan: the list, the check and the download must work without a key): the **sidecar file** `<id>.json` holds only the *minimal* manifest - the time, the application and its version, the profile id (instead of its name), the number of files, directories, tables and rows and their sizes, the part files with their sizes and SHA-256, the status, and the short forms of the public keys (`age1xxxxxxxx...yyyyyy`) or "passphrase". It holds **no** domain, path, table name, filter or database fact, and its warnings are only counted. The file names (profile id, time, a random code), the sizes and the times of the files are visible to whoever can list the directory.

**Not protected:**
- Anyone who can **change the profile** can add a public key of their own: later backups are then also readable for them. Look at the keys of the profile now and then (the profile page and the details page of every backup show their short forms).
- While a backup is made, the plain archive part lies in the work directory (`.work-<id>/parts/` inside the storage directory, mode 0700) until its encrypted file is complete, and so do the dump files. Keep the storage directory out of reach of the web, as for any backup. And the profile holds the database password.
- A **passphrase typed into the web page** crosses the connection (use https; the login page asks you to confirm plain http) and lies in the state file of the run (mode 0600) until the run ends.
- The secret key of a restore typed into the wizard crosses the connection to the *target* server and lies in the state of the restore until the files are unpacked.

## 3. Keys

A key pair is two lines of the `age` format: the **public key** `age1...` (not secret; it goes into the profile) and the **secret key** `AGE-SECRET-KEY-1...` (opens the backups; the server never holds it).

- **On the command line:** `keys generate` shows the key file once - `# created`, `# public key`, the secret key - exactly as `age-keygen` writes it, so `age -d -i key.txt` accepts it. `--output=FILE` writes it with mode 0600 and never over an existing file. You may also make the pair on your own computer with `age-keygen` and enter only the public key: then the secret key never touches the server.
- **In the browser** (profile → *Keys* → *Make a key pair*): the page shows the key file **once**, with buttons **Copy**, **Save as a file** and **Print** (the print view shows only the key and a sentence saying what it opens), the public key with its own *Copy* button, and a tick *I have stored the secret key somewhere else than on this server*. Only when the tick is set does *Use this public key* put the public key into the profile. ThreadSafe keeps nothing of the secret half: it is not in the profile, the session, the audit log (the public key is) or any file of the data directory.
- **Several keys.** A profile may have several public keys; every backup is encrypted for all of them and **any one** of the secret keys opens it. Use that for a second person, or for a spare key kept in another place. Entering the key checks its checksum, so a mistyped key is refused when you enter it and not when the first backup needs it.
- **Changing keys.** A new public key applies to the **next** backup; backups made earlier stay readable only for the keys they were made for, so keep the old secret key as long as those backups are kept. The simple way to have a backup for the new key is a new backup. (Encrypting an old part again by hand with `age` is possible, but the file then has another SHA-256 than the sidecar says, and the check calls it damaged.)
- **Where to keep it:** a password manager, a printout in a safe, another machine. One copy is a risk; two in different places is a habit.

## 4. A passphrase instead of keys, for one backup

`backup --passphrase-file=FILE` (or the environment variable `THREADSAFE_PASSPHRASE`) and the field *Encrypt this backup with a passphrase* on the start form encrypt **that one backup for the passphrase only**. The format does not allow a passphrase together with public keys in one file (a file for a passphrase must not open with anything weaker), so such a backup ignores the keys of the profile, and the pages say so.

- `keys passphrase [--output=FILE]` makes one: five groups of four characters from an alphabet without look-alikes (`kr7m-xq3d-hp9t-w4nc-2vfe`), about 99 bits; the button **Suggest one** on the page does the same in your browser (nothing is sent), next to **Copy**. A passphrase you choose yourself is accepted if it has at least 12 characters.
- The protection is `scrypt` with a work factor of 2^15, eight times less work for an attacker than the 2^18 of the `age` tool itself: a made-up passphrase can be guessed, a generated one cannot. That is why short ones are refused.
- It is **not stored anywhere**: write it down before you start. It lives in the state file of the run (mode 0600) until the run ends and is blanked then.
- The scheduled backups cannot have a passphrase (nobody is there to type it); they use the public keys of the profile.

## 5. How a backup is made, and what it costs

The run goes through the usual stages and two more: after **pack** come **close** (the last part is closed, the finishing time is fixed) and **seal**. A part is sealed as soon as it is closed, block by block, in steps like everything else: the position, the running SHA-256 and the state of the sealer are saved after every block, and a run that is interrupted goes on from the last sealed block. When a sealed file is complete and has exactly the size the plain size and the format dictate, it is renamed, set to mode 0600, and the plain part is deleted. So at most **one plain part** waits besides the one being packed, and no plain archive ever lies in the storage directory.

- **Disk space:** besides the sealed files, room for one plain part and the dump files. For a large site set a `partSize` (for example 500M): that keeps the plain data on the disk small. The free-space check counts this.
- **Time:** the cipher is not the cost (about 1 GB/s measured in PHP); reading and writing the parts is.
- A sealed part is a few hundred bytes larger than its plain part (the header, 16 bytes per 64 KiB).

## 6. Listing, checking, downloading, deleting - without a key

- The **list** and the **details page** mark a backup "encrypted" and say for which keys, or "for a passphrase only".
- The **quick check** (size and SHA-256 of the sealed files) needs no key. The **full check** reads every part through the decryption and then checks it as for a plain backup, and compares the manifest inside with the minimal one of the sidecar: `verify <profile> --identity-file=FILE` or `--passphrase-file=FILE`. Every block is authenticated, so this is a stronger check than the CRC of a plain archive. Without a key a full check says so and does the quick one. **The web interface has no key and does the quick check only**; it never asks for a secret key (only the restore wizard on the target does).
- **Download** sends the sealed file as it is on the server (`application/octet-stream`). **Delete** and **retention** work as for any backup.
- **The sidecar of an encrypted backup cannot be made again from the archive** (that would need the key). If it is lost, the backup is listed as "unknown" with the reason; the files are intact and `age -d` opens them. A pin lives in the sidecar and is lost with it.

## 7. Restoring

`restore` and `threadsafe-restore.php` take the secret key or the passphrase ([RESTORE.md](RESTORE.md) section 12): `--identity-file=FILE`, `--passphrase-file=FILE` (or `THREADSAFE_PASSPHRASE`), or the field on the first page of the wizard. The parts are read through a decrypting stream, one block at a time, so nothing is decrypted to disk and the free space needed is that of a plain restore. A wrong key is refused with the name of the part **before anything is written**. The target PHP needs `ext-sodium`; a backup for a passphrase costs about three seconds and 42 MB of key derivation per step of the restore.

## 8. With the real `age` tool

The files are standard. To open one without ThreadSafe:

```bash
age -d -i shop-key.txt -o shop_..._p01.zip shop_..._p01.zip.age      # a key file: the parts are ZIP archives
unzip shop_..._p01.zip                                                 # ordinary tools from here on (BACKUP.md section 8)
age -d -o part.zip part.zip.age                                        # a passphrase: age asks for it on the terminal
```

`age-keygen -y shop-key.txt` prints the public key of a key file. A file that `age` itself sealed for a passphrase (work factor 2^18) opens with ThreadSafe on the command line with `php -d memory_limit=512M` (about 21 seconds), not in a wizard with 64 MB; the message names the way out.

## 9. The format, in short

`age` v1 without armor and without plugins: recipients of type `X25519` (public keys) or `scrypt` (a passphrase, alone in the file), a header with a MAC, a random 16-byte nonce, the payload in 64 KiB blocks of ChaCha20-Poly1305, the last block marked. ThreadSafe's reader is **stricter than the reference reader** where it can be (it knows the file size, so a file whose last block is not marked as the last is refused before that block is released; a passphrase combined with keys is refused). It is checked against the test vectors of the age project (92 files in `tests/fixtures/age-cctv/`) and against the real tool in both directions. The code is `src/Crypto/`.

## 10. When something does not work

| You see | Meaning and what to do |
|---|---|
| `... is encrypted. Give the secret key ... or the passphrase` | A restore or check needs the key: `--identity-file=FILE` / `--passphrase-file=FILE`, or the field of the wizard. |
| `The key ... does not open this file` / no key of the file matches | The wrong key: a backup is opened only by a secret key that belongs to one of its public keys (the details page shows their short forms) or by its passphrase. A backup for a passphrase is not opened by a key, nor the other way round. |
| A passphrase of the file "needs about N MB of memory" | The work factor is higher than this PHP may afford (a file sealed by `age` itself). Use `age -d`, or the command line with a higher `memory_limit`. |
| The PHP extension `sodium` is missing | Encrypting and decrypting need it (part of PHP 8.4 builds). Ask the host to enable it, or decrypt elsewhere with `age`. |
| `The passphrase is short` | Under 12 characters and not made by the program: use `keys passphrase` or the *Suggest one* button. |
| `Backup ... is NOT intact` after a full check with the key | A block does not authenticate: the file was changed or damaged after it was written. Use another copy. |
| "unknown" in the list for an encrypted backup | Its sidecar file is lost and cannot be made again without the key; the files are intact. |

## 11. Limits

- No transfer of encrypted backups to other places yet (a later phase); copy them with your own tools - they are safe to hand around.
- The web interface never decrypts: no full check, no listing of the files inside.
- A passphrase backup is for the passphrase only; there is no "key and passphrase" file.
- Old backups are not re-encrypted for a new key; make a new backup (section 3).
