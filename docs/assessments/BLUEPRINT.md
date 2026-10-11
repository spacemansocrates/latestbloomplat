# Bloom diagnostic assessment blueprint

This is the spec for every diagnostic assessment in the Bloom portal. Read it all before you write
or change an assessment. A new assessment is one JSON file in `assessments/bank/`, checked by
`tools/validate_assessment.php`. The portal reads the file directly, so you do not need a database import.

## To build a new assessment on command

Example request: *"Read docs/assessments/BLUEPRINT.md and build the KS2 science assessment."*

1. Read this whole file, then one existing file in `assessments/bank/` as a worked example.
2. Pick the skills (4 to 6) from the official source for that stage (see "Curriculum sources").
3. Write the file as `assessments/bank/<stage>-<subject>-v<version>.json`, all lowercase,
   for example `ks2-science-v1.json`.
4. Run `php tools/validate_assessment.php assessments/bank/ks2-science-v1.json` and fix every
   ERROR. Read every WARNING and fix it unless you have a clear reason not to.
5. Solve every question yourself a second time, from scratch, without looking at the answer key.
   Recompute every numeric answer. Wrong answer keys are the worst possible bug here, because a
   parent gets told their child has a gap they do not have.
6. Never change a version that students have already taken. Copy it to `v<N+1>`, change the
   copy, and bump `version`. Each attempt stores the assessment id and version, so old reports
   stay correct.

## What the assessment is for

It runs inside the free 45-minute demo session: about 25 minutes of questions, then the tutor
teaches one gap live. It has one job: **show clearly and fairly where the child is strong and
where the gaps are**, one skill at a time. It is a diagnostic, not an exam. It does not give a grade.
The results feed a report that is emailed to the parent after the tutor approves it.

## Stages, subjects and size

| Stage | Years | Questions | Minutes |
|---|---|---|---|
| KS1  | 1 to 2   | 12 to 16 | 20 |
| KS2  | 3 to 6   | 16 to 20 | 25 |
| KS3  | 7 to 9   | 18 to 24 | 25 |
| GCSE | 10 to 11 | 20 to 24 | 25 |

Subjects: `maths`, `english`, `science`. A-Level comes later and is not covered by v1 of this spec.

One assessment per stage and subject covers every year in that stage. The difficulty rises through
the paper, so a Year 7 and a Year 9 student both land in the right place.

**GCSE assessments work for every exam board** (AQA, Edexcel, OCR, WJEC). Write only from the
DfE subject content that every board shares. Never use board-specific topics, set texts or
required-practical names. The exam board goes on the report and plan, not the paper.

## Difficulty scale (1 to 5)

Difficulty is relative to the stage.

| Level | KS1 / KS2 / KS3 | GCSE |
|---|---|---|
| 1 | Secure from the previous stage | Foundation, grades 1 to 2 |
| 2 | Early in this stage | Foundation, grade 3 |
| 3 | Expected for the middle of this stage | Grades 4 to 5 (tier overlap) |
| 4 | Expected for the end of this stage | Higher, grades 6 to 7 |
| 5 | Greater depth | Higher, grades 8 to 9 |

Rules:
- Every skill has at least one question at level 1 or 2, and at least one at level 4 or 5.
- Order questions by rising difficulty overall. Mix the skills, and avoid two questions in a row
  from the same skill where you can.

## Skills

Each assessment has 4 to 6 skills. A skill is a strand a parent would recognise, such as
"Fractions, decimals and percentages" or "Reading for inference". Avoid "Number 2" or "Y5 NC
objective 3a" style names.

Each skill needs at least 3 questions and at least 3 marks, so one careless slip cannot flag it as a gap.

Each skill carries the parent-facing copy the report uses. Write it warm, plain and specific.
Use `{name}` where the child's first name goes. Each field is 2 sentences at most. Use no jargon
and no em dashes.

| Field | Shown when | Purpose |
|---|---|---|
| `why` | always | Why this skill matters, and what it unlocks next |
| `strong` | band is Strong | Praise that names what they can do |
| `developing` | band is Developing | What is there, and what is still shaky |
| `gap` | band is Gap | What is missing, and what it will affect if left |
| `next_step` | Developing or Gap | What the tutor will do about it |

The `gap` text must be honest, not frightening. Good: *"{name} is not yet confident adding
fractions with different denominators. This comes up again in algebra, so it is worth fixing
now."* Bad: *"{name} is falling seriously behind."*

## Question types

| `type` | Scored by | Answer field |
|---|---|---|
| `mcq`     | auto  | `answer`: index of the correct option, starting at 0 |
| `multi`   | auto  | `answer`: array of correct indexes. Full marks only if exactly right |
| `numeric` | auto  | `answer`: number. Optional `tolerance` (default 0) and `unit` |
| `short`   | auto  | `answer`: array of accepted strings. Matching ignores case, spaces and a trailing full stop |
| `written` | tutor | `rubric`: list of `{marks, criterion}`. The marks must add up to `marks` |

Rules:
- `mcq` has exactly 4 options: one correct, three distractors. Each distractor comes from a real
  misconception (for example 1/2 + 1/3 = 2/5), never filler. Never use "all of the above" or
  "none of the above". Spread the correct position across A to D.
- `short` is for one word or a very short phrase that has a small set of correct forms. List them
  all ("photosynthesis", "photo synthesis"). If a correct answer can be worded many ways, make it
  `written` instead.
- `written` is for English writing and the occasional "explain" question in science. There can be
  at most 2 per assessment, and they can carry at most 25% of the total marks. Each one delays the
  report until the tutor marks it.
- Every question has an `explain` field. It holds the working or reason for the tutor. Parents
  never see it.

## Content rules

- Text only in v1: no images and no diagrams. Write maths in plain text with Unicode: `3/4`, `x²`,
  `√2`, `×`, `÷`, `π`, `≤`. If a question needs a diagram to make sense, drop it.
- Keep each question self-contained, with one clear thing to answer.
- Use UK spelling and UK contexts: £ and pence, metric units, UK place names.
- Reading age fits the stage, which matters most for KS1. A maths question must never fail
  because the child could not read it.
- **KS1 is read aloud.** Year 1 and 2 children often cannot read test questions alone, so the
  tutor reads each question to them. Give every KS1 file an `intro` that tells the child this
  (see "File format"). Keep KS1 stems to one short sentence where you can. In English, the
  child still reads the story and the spelling options on their own, and the intro says so.
- KS2 follows the Year 6 SATs strands, so parents recognise them: maths covers arithmetic and
  reasoning; English covers reading plus grammar, punctuation and spelling, with one short
  writing task.
- English reading passages go in `passages` and questions point at them with `passage`. **Every
  passage is original writing.** Never quote, adapt or paraphrase a published text, song or poem.
  Keep them to 120 to 350 words, depending on stage.
- `ref` holds the curriculum reference (a programme of study statement or DfE content code) so a
  tutor can see what each question tests.

## Curriculum sources

- KS1 to KS3: National Curriculum in England, programmes of study (DfE, 2013/2014).
- GCSE maths: DfE GCSE mathematics subject content (2013).
- GCSE English: DfE GCSE English language subject content (2013). English literature is left
  out because every board uses different set texts.
- GCSE science: DfE GCSE combined science subject content (2015). Write combined-science-level
  questions. Triple-only content goes at difficulty 5 at most, and only sparingly.

## Scoring and the report

These rules live in the portal code. They are written down here so every author knows how their
questions will be used.

- Skill score = marks earned ÷ marks available for that skill, as a percentage.
- Bands: **Strong** 75% and above, **Developing** 40% to 74%, **Gap** below 40%. You can change
  them for one assessment with `bands`.
- The report shows each skill with its band and copy, and every score out of 10.
- The plan is chosen automatically from the overall score and the family's pace (set when the
  demo is booked). Tutors never see prices and cannot change the plan:

| Overall score | Plan from the score |
|---|---|
| 75% and above | Starter, 4 lessons |
| 50% to 74% | Core, 8 lessons |
| Below 50% | Intensive, 12 lessons |

  Pace then moves it up: steady +0, clear progress within a term +1, as fast as possible +2,
  capped at Intensive+ (16 lessons). Nothing is emailed until a tutor or admin approves it.
- **Icons.** Each skill shows a line icon on the score dial and in the email. The icon comes
  from `ASM_SKILL_ICONS` in `utils/assessment_ring.php`, keyed by skill `id`, or from an
  optional `"icon"` field on the skill (the name of a PNG in `assets/assessment-icons/`). Reuse
  an existing skill id where it fits. For a new one, add it to `ASM_SKILL_ICONS`; if no icon
  fits, draw one in `tools/render_assessment_icons.html`. No two skills in one assessment may
  share an icon, and `php tests/assessment/run.php` checks both rules.

## File format

```json
{
  "id": "gcse-maths",
  "version": 1,
  "subject": "maths",
  "stage": "GCSE",
  "years": [10, 11],
  "title": "GCSE Maths diagnostic",
  "minutes": 25,
  "curriculum": "DfE GCSE mathematics subject content (all boards)",
  "intro": "Optional. One or two sentences shown to the student above the questions.",
  "bands": { "strong": 75, "developing": 40 },
  "skills": [
    {
      "id": "fractions",
      "name": "Fractions, decimals and percentages",
      "why": "Fractions and percentages are used across almost every GCSE topic, from ratio to probability.",
      "strong": "{name} works confidently with fractions, decimals and percentages.",
      "developing": "{name} handles simple fractions well but is less sure once the numbers get harder.",
      "gap": "{name} is not yet confident adding fractions with different denominators. This comes up again in algebra, so it is worth fixing now.",
      "next_step": "We will rebuild this from equivalent fractions up, with lots of short practice."
    }
  ],
  "passages": [],
  "questions": [
    {
      "id": "q01",
      "skill": "fractions",
      "difficulty": 1,
      "type": "mcq",
      "marks": 1,
      "ref": "N10",
      "stem": "What is 1/2 + 1/4?",
      "options": ["2/6", "3/4", "1/6", "2/4"],
      "answer": 1,
      "explain": "1/2 = 2/4, so 2/4 + 1/4 = 3/4. 2/6 is the add-tops-and-bottoms misconception."
    },
    {
      "id": "q02",
      "skill": "fractions",
      "difficulty": 3,
      "type": "numeric",
      "marks": 1,
      "ref": "R9",
      "stem": "A coat costs £80. It is reduced by 15%. What is the new price in pounds?",
      "answer": 68,
      "tolerance": 0,
      "unit": "£",
      "explain": "15% of 80 = 12, and 80 - 12 = 68."
    }
  ]
}
```

Field notes:
- `id` is `<stage>-<subject>` in lowercase, and it stays the same across versions.
- Question `id`s are unique within the file. Use `q01`, `q02` and so on.
- `passages` items are `{ "id": "p1", "title": "...", "text": "..." }`.
- `written` questions use `rubric` instead of `answer`. They take no `options`.
- `intro` is optional plain text, under 200 characters. Leave it out unless the stage needs it
  (every KS1 file has one).
- `numeric` answers are read as plain numbers, so "£1.50", "12 cm" and "x = 5" all work, but a
  fraction such as "3/4" does not. Use `mcq` when the answer is a fraction.
