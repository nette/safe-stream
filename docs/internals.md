# SafeStream internals

A single stream wrapper (`nette.safe://`) that makes file read/write **isolated**:
`LOCK_SH` for reads (many readers), `LOCK_EX` for writes (one writer). Only pure
`r` mode counts as a read — anything else, **including `r+`**, takes the exclusive
lock. The value is in three non-obvious tricks that make the locking actually
correct.

## The `w` → `c` mode conversion is the crux

The trap a naive implementation falls into: **PHP's `w` mode truncates the file at
`fopen`, *before* any lock is held.** A concurrent reader could then acquire its
shared lock and see an empty file. So `stream_open` **rewrites a `w` mode to `c`**
(create without truncate), acquires `LOCK_EX`, and only **then** `ftruncate`s to 0
manually. Truncation therefore happens under the lock, never in the unlocked window.
(Append `a` mode instead records `startPos = current size` for rollback; see below.)

## Reads retry while the file is empty

Even with `LOCK_SH`, a reader can win the lock in the instant a writer has opened
(`c` mode, no data yet) but not written. To avoid reading a transiently-empty file,
a read whose `fstat size` is 0 **releases the shared lock, `usleep`s, and re-acquires
it — up to 100 times.** This is the counterintuitive part: holding a shared lock is
not enough; the reader must also spin past the empty-mid-write window. After 100
fruitless attempts the open still **succeeds** and the caller reads the empty file —
the spin is best-effort mitigation, not a guarantee.

## Write errors roll back on close

`stream_write` sets a `writeError` flag when `fwrite` fails or returns fewer bytes
than asked (e.g. disk full). On `stream_close`, if that flag is set, the file is **`ftruncate`d
back to `startPos`** (0 for a fresh `w`, the original size for append) before the
lock is released — so a failed write **never leaves partially written data behind**;
the file is restored to its pre-write size.

## Boundaries that are *not* isolated

- **Metadata queries bypass the lock.** `url_stat` (`file_exists`, `filesize`,
  `is_file`, …) is explicitly **not thread-safe** — it `@stat`s the path directly and
  may report on a file another thread is mid-write.
- **Windows can't unlink an open file.** `unlink('nette.safe://…')` can fail on
  Windows if another thread holds the file open (a platform limit, not a bug).
