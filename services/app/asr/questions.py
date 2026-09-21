"""
Question tracker: which interview question is being answered right now?

Three layers, all driven by *interviewer-labelled final* segments (the
candidate cannot move the interview forward):

  1. match   - the interviewer reads the question from the screen, so the
               transcript resembles the stored question text. Fuzzy
               similarity (token Jaccard blended with difflib ratio) against
               the questions not asked yet; best >= match_threshold wins.
  2. phrase  - configurable trigger phrases such as "lanjut ke pertanyaan
               berikutnya", matched fuzzily so ASR slips still count;
               advances to the next unasked question in order.
  3. manual  - the client sends {"type": "set_question"}; always accepted.

Every decision is returned as a QuestionChange so the client can show it
and the session page can correct it afterwards.
"""

from __future__ import annotations

import difflib
import re
from dataclasses import dataclass

_WORD = re.compile(r"[a-z0-9]+")

DEFAULT_TRIGGER_PHRASES = [
    "lanjut ke pertanyaan berikutnya",
    "pertanyaan berikutnya",
    "pertanyaan selanjutnya",
    "lanjut ke pertanyaan selanjutnya",
    "next question",
    "pertanyaan terakhir",
]


def normalize(text: str) -> list[str]:
    return _WORD.findall(text.lower())


def similarity(a: str, b: str) -> float:
    """0..1. Token Jaccard catches paraphrase/ASR slips; difflib ratio rewards word order."""
    ta, tb = normalize(a), normalize(b)
    if not ta or not tb:
        return 0.0
    sa, sb = set(ta), set(tb)
    jaccard = len(sa & sb) / len(sa | sb)
    ratio = difflib.SequenceMatcher(None, ta, tb).ratio()
    return round(0.5 * jaccard + 0.5 * ratio, 3)


def contains_phrase(text: str, phrase: str, min_ratio: float = 0.8) -> float:
    """Best fuzzy match of `phrase` inside `text` over a sliding window of the same word count."""
    words, target = normalize(text), normalize(phrase)
    if not target or len(words) < len(target):
        return 0.0
    best = 0.0
    n = len(target)
    for i in range(len(words) - n + 1):
        r = difflib.SequenceMatcher(None, words[i:i + n], target).ratio()
        best = max(best, r)
    return best if best >= min_ratio else 0.0


@dataclass
class QuestionChange:
    question_id: int
    reason: str            # match | phrase | manual
    score: float | None
    segment_id: int | None
    text: str | None


class QuestionTracker:
    def __init__(
        self,
        questions: list[dict],                 # [{"id": 12, "text": "..."}] in interview order
        trigger_phrases: list[str] | None = None,
        match_threshold: float = 0.45,
        phrase_ratio: float = 0.8,
    ):
        self.questions = questions
        self.trigger_phrases = trigger_phrases or DEFAULT_TRIGGER_PHRASES
        self.match_threshold = match_threshold
        self.phrase_ratio = phrase_ratio
        self.active_id: int | None = None
        self.asked: list[int] = []

    # ---------------------------------------------------------------- api

    def on_interviewer_final(self, text: str, segment_id: int) -> QuestionChange | None:
        if not text.strip():
            return None

        # Layer 1: does this sound like one of the remaining questions?
        best_id, best_score = None, 0.0
        for q in self.questions:
            if q["id"] in self.asked:
                continue
            s = similarity(text, q["text"])
            if s > best_score:
                best_id, best_score = q["id"], s
        if best_id is not None and best_score >= self.match_threshold:
            return self._activate(best_id, "match", best_score, segment_id, text)

        # Layer 2: trigger phrase -> next unasked question in order.
        phrase_score = max((contains_phrase(text, p, self.phrase_ratio) for p in self.trigger_phrases), default=0.0)
        if phrase_score > 0:
            nxt = self._next_unasked()
            if nxt is not None:
                return self._activate(nxt, "phrase", phrase_score, segment_id, text)
        return None

    def set_manual(self, question_id: int) -> QuestionChange | None:
        if any(q["id"] == question_id for q in self.questions):
            return self._activate(question_id, "manual", None, None, None)
        return None

    # ----------------------------------------------------------- internals

    def _next_unasked(self) -> int | None:
        for q in self.questions:
            if q["id"] not in self.asked:
                return q["id"]
        return None

    def _activate(self, qid: int, reason: str, score, segment_id, text) -> QuestionChange:
        self.active_id = qid
        if qid not in self.asked:
            self.asked.append(qid)
        return QuestionChange(qid, reason, score, segment_id, text)
