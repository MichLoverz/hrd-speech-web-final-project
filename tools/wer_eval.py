"""
WER/CER scoring with explicit, switchable text normalisation.

    python tools/wer_eval.py --ref REF.txt --hyp HYP1.txt HYP2.txt --labels base small

Why this exists: "lima menit" and "5 menit" mean the same thing, but a plain
string comparison counts one as a substitution of the other. Scoring without
number normalisation therefore reports a WER that is worse than the system
actually is.

The opposite mistake is worse, though: normalisation that is too generous
flatters the numbers. So every run prints BOTH the raw and the normalised
score, and the normalisation steps applied are named in the output. The gap
between the two is itself a reportable figure.

Only cardinal numbers are normalised. Ordinals ("pertama"), fractions and
spelled-out units are left alone deliberately -- mapping them needs judgement
calls that would quietly change what is being measured.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path

import jiwer

# --- Indonesian cardinal numerals -------------------------------------------

UNITS = {
    "nol": 0, "kosong": 0,
    "satu": 1, "dua": 2, "tiga": 3, "empat": 4, "lima": 5,
    "enam": 6, "tujuh": 7, "delapan": 8, "sembilan": 9,
}
# Words that carry their own value and need no preceding unit.
STANDALONE = {"sepuluh": 10, "sebelas": 11, "seratus": 100}
MULTIPLIERS = {"puluh": 10, "ratus": 100}
BIG = {"ribu": 1_000, "juta": 1_000_000, "miliar": 1_000_000_000,
       "milyar": 1_000_000_000, "triliun": 1_000_000_000_000}
# "seribu" == "satu ribu"; the se- prefix means one.
SE_BIG = {"seribu": "ribu", "sejuta": "juta", "semiliar": "miliar", "semilyar": "miliar"}

CONTINUATION = set(MULTIPLIERS) | {"belas"}
NUMBER_WORDS = set(UNITS) | set(STANDALONE) | set(MULTIPLIERS) | set(BIG) | set(SE_BIG) | {"belas"}


def _parse_number_run(words: list[str]) -> int:
    """Fold a run of numeral words into one integer. 'dua puluh lima' -> 25."""
    total = current = 0
    pending: int | None = None
    for w in words:
        if w in SE_BIG:
            w, pending = SE_BIG[w], 1
        if w.isdigit():
            pending = int(w)
        elif w in UNITS:
            pending = UNITS[w]
        elif w in STANDALONE:
            current += STANDALONE[w]
            pending = None
        elif w == "belas":
            current += 10 + (pending or 0)
            pending = None
        elif w in MULTIPLIERS:
            current += MULTIPLIERS[w] * (pending if pending is not None else 1)
            pending = None
        elif w in BIG:
            base = current + (pending or 0)
            total += (base or 1) * BIG[w]
            current = 0
            pending = None
    return total + current + (pending or 0)


def words_to_digits(text: str) -> str:
    """Replace every run of numeral words with its digits.

    Mixed digit+word numerals count too: Indonesian speech and ASR output are
    full of '80 juta' and '5 ribu'. A bare digit only joins a run when a
    multiplier follows it, so that two unrelated numbers standing side by side
    ('index 2 3') are never silently folded into one token.
    """
    out: list[str] = []
    run: list[str] = []
    toks = text.split()

    def flush() -> None:
        if run:
            out.append(str(_parse_number_run(run)))
            run.clear()

    for i, tok in enumerate(toks):
        nxt = toks[i + 1] if i + 1 < len(toks) else ""
        if tok.isdigit():
            if nxt in BIG or nxt in CONTINUATION:
                run.append(tok)
            else:
                flush()
                out.append(tok)
        elif tok in NUMBER_WORDS:
            # 'belas', 'puluh' and 'ratus' only continue a run, they never start one.
            if not run and tok in CONTINUATION:
                out.append(tok)
                continue
            run.append(tok)
        else:
            flush()
            out.append(tok)
    flush()
    return " ".join(out)


def normalise_digit_groups(text: str) -> str:
    """Indonesian writes 1.000 for a thousand and 1,5 for one and a half."""
    text = re.sub(r"(?<=\d)\.(?=\d{3}\b)", "", text)
    text = re.sub(r"(?<=\d),(?=\d)", ".", text)
    return text


# --- normalisation pipeline --------------------------------------------------

def strip_punctuation(text: str, mode: str = "remove") -> str:
    """Drop punctuation, keeping a dot that sits between two digits (1.5).

    mode='remove' deletes it, so 'query-nya' becomes 'querynya'.
    mode='space' replaces it, so 'query-nya' becomes two words.

    The choice matters in Indonesian, where the hyphen both attaches clitics
    ('query-nya') and marks reduplication ('kata-kata'), and Whisper is not
    consistent about writing it. 'remove' is the default because every figure
    recorded before 2026-09-23 was computed that way; changing it silently
    would make old and new rows in the ledger incomparable.
    """
    kept = []
    last = len(text) - 1
    for i, ch in enumerate(text):
        if ch.isalnum() or ch.isspace():
            kept.append(ch)
        elif ch == "." and 0 < i < last and text[i - 1].isdigit() and text[i + 1].isdigit():
            kept.append(ch)
        elif mode == "space":
            kept.append(" ")
    return "".join(kept)


def normalise(text: str, numbers: bool = True, punct: str = "remove") -> str:
    text = unicodedata.normalize("NFKC", text).lower()
    text = text.replace("’", "'").replace("‘", "'")
    if numbers:
        text = normalise_digit_groups(text)
    text = strip_punctuation(text, punct)
    text = re.sub(r"\s+", " ", text).strip()
    if numbers:
        text = words_to_digits(text)
    return text


# --- scoring -----------------------------------------------------------------

@dataclass
class Score:
    wer: float
    cer: float
    substitutions: int
    deletions: int
    insertions: int
    hits: int
    ref_words: int
    errors: list[tuple[str, str, str]] = field(default_factory=list)

    def as_row(self) -> dict:
        return {"wer": round(self.wer, 4), "cer": round(self.cer, 4),
                "S": self.substitutions, "D": self.deletions, "I": self.insertions,
                "ref_words": self.ref_words}


def score(ref: str, hyp: str, numbers: bool, punct: str = "remove") -> Score:
    r, h = normalise(ref, numbers, punct), normalise(hyp, numbers, punct)
    w = jiwer.process_words(r, h)
    c = jiwer.process_characters(r, h)
    errors: list[tuple[str, str, str]] = []
    rw, hw = w.references[0], w.hypotheses[0]
    for chunk in w.alignments[0]:
        if chunk.type == "equal":
            continue
        errors.append((
            chunk.type,
            " ".join(rw[chunk.ref_start_idx:chunk.ref_end_idx]),
            " ".join(hw[chunk.hyp_start_idx:chunk.hyp_end_idx]),
        ))
    return Score(wer=w.wer, cer=c.cer, substitutions=w.substitutions,
                 deletions=w.deletions, insertions=w.insertions, hits=w.hits,
                 ref_words=len(rw), errors=errors)


def score_corpus(refs: list[str], hyps: list[str], numbers: bool, punct: str = "remove") -> Score:
    """Corpus WER over many utterances: (S+D+I) summed, divided by total reference words.

    Not the mean of per-utterance WERs -- that would let a three-word utterance
    weigh as much as a thirty-word one. jiwer aggregates this way when given
    lists, and it is what published benchmark numbers mean.
    """
    r = [normalise(x, numbers, punct) for x in refs]
    h = [normalise(x, numbers, punct) for x in hyps]
    # jiwer refuses empty references; keep the pair only if the reference has words.
    pairs = [(a, b) for a, b in zip(r, h) if a.strip()]
    r, h = [a for a, _ in pairs], [b for _, b in pairs]
    w = jiwer.process_words(r, h)
    c = jiwer.process_characters(r, h)
    return Score(wer=w.wer, cer=c.cer, substitutions=w.substitutions,
                 deletions=w.deletions, insertions=w.insertions, hits=w.hits,
                 ref_words=sum(len(x.split()) for x in r))


def read(path: str) -> str:
    return Path(path).read_text(encoding="utf-8").strip()


# --- self-test ---------------------------------------------------------------
# There is no pytest in services/.venv (the project's 54 tests are Laravel's),
# so the numeral rules carry their own cases. Run: python tools/wer_eval.py --self-test

SELF_TEST_CASES = [
    # plain word numerals
    ("lima menit", "5 menit"),
    ("sepuluh", "10"),
    ("sebelas", "11"),
    ("dua belas", "12"),
    ("dua puluh lima", "25"),
    ("dua ratus tiga puluh empat", "234"),
    ("seratus dua puluh lima ribu", "125000"),
    ("seribu", "1000"),
    ("tiga juta lima ratus ribu", "3500000"),
    ("nol koma lima", "0 koma 5"),
    # mixed digit + word, the form ASR actually produces
    ("80 juta rupiah", "80000000 rupiah"),
    ("delapan juta rupiah", "8000000 rupiah"),
    ("5 ribu", "5000"),
    ("2 miliar", "2000000000"),
    ("tiga bulan", "3 bulan"),
    # things that must be left alone
    ("langkah pertama biasanya", "langkah pertama biasanya"),
    ("n plus satu query", "n plus 1 query"),
    ("belas", "belas"),
    ("index 2 3", "index 2 3"),
    ("versi 5 dan 7", "versi 5 dan 7"),
    ("port 8000 dan 8765", "port 8000 dan 8765"),
]

SELF_TEST_NORMALISE = [
    ("Rp 1.000.000", "rp 1000000"),
    ("1,5 detik", "1.5 detik"),
    ("sekitar delapan juta rupiah", "sekitar 8000000 rupiah"),
    ("sekitar 5 menit.", "sekitar 5 menit"),
]


def self_test() -> int:
    failures = 0
    for src, want in SELF_TEST_CASES:
        got = words_to_digits(src)
        if got != want:
            failures += 1
            print(f"FAIL words_to_digits({src!r}) = {got!r}, want {want!r}")
    for src, want in SELF_TEST_NORMALISE:
        got = normalise(src)
        if got != want:
            failures += 1
            print(f"FAIL normalise({src!r}) = {got!r}, want {want!r}")
    total = len(SELF_TEST_CASES) + len(SELF_TEST_NORMALISE)
    print(f"{total - failures}/{total} cases pass")
    return failures


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--self-test", action="store_true", help="check the numeral rules and exit")
    p.add_argument("--ref", help="reference transcript (what was actually said)")
    p.add_argument("--hyp", nargs="+", help="one or more ASR outputs to score")
    p.add_argument("--labels", nargs="*", default=None, help="names for the hypotheses, in order")
    p.add_argument("--show-errors", type=int, default=0, help="print the first N error chunks per hypothesis")
    p.add_argument("--punct", choices=["remove", "space"], default="remove",
                   help="how punctuation is handled; 'remove' matches every figure recorded before 2026-09-23")
    p.add_argument("--json", default=None, help="write the results here")
    a = p.parse_args()

    if a.self_test:
        sys.exit(1 if self_test() else 0)
    if not a.ref or not a.hyp:
        p.error("--ref and --hyp are required (or use --self-test)")

    labels = a.labels or [Path(h).stem for h in a.hyp]
    if len(labels) != len(a.hyp):
        sys.exit(f"--labels has {len(labels)} entries but --hyp has {len(a.hyp)}")

    ref = read(a.ref)
    print(f"reference: {a.ref}  ({len(normalise(ref, False, a.punct).split())} words, punct={a.punct})\n")
    # S/D/I are counted on the normalised text; the JSON carries both sets.
    print(f"{'hypothesis':<22} {'WER raw':>8} {'WER num':>8} {'delta':>7} {'CER raw':>8} {'CER num':>8} "
          f"{'S*':>4} {'D*':>4} {'I*':>4}")

    results = {}
    for label, path in zip(labels, a.hyp):
        hyp = read(path)
        raw = score(ref, hyp, numbers=False, punct=a.punct)
        num = score(ref, hyp, numbers=True, punct=a.punct)
        print(f"{label:<22} {raw.wer:8.3f} {num.wer:8.3f} {num.wer - raw.wer:+7.3f} "
              f"{raw.cer:8.3f} {num.cer:8.3f} {num.substitutions:4d} {num.deletions:4d} {num.insertions:4d}")
        results[label] = {"file": path, "raw": raw.as_row(), "normalised": num.as_row()}
        if a.show_errors:
            for kind, r, h in num.errors[:a.show_errors]:
                print(f"    {kind:<12} ref={r!r:<40} hyp={h!r}")

    print(f"\nraw        = lowercase + punctuation ({a.punct})")
    print("normalised = the same, plus Indonesian cardinal numbers written as digits")
    print("* S/D/I are counted on the normalised text")

    if a.json:
        payload = {"reference": a.ref, "punct": a.punct, "results": results}
        Path(a.json).write_text(json.dumps(payload, indent=2), encoding="utf-8")
        print(f"\nwritten: {a.json}")


if __name__ == "__main__":
    main()
