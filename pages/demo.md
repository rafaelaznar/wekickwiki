
# WeKickWiki — Parser Demo & Test Suite

This page exercises all markdown syntax elements supported by the wiki,
with special attention to combinations that may produce unexpected or
incorrect results.

## 1. Headings

# h1 Heading

## h2 Heading

### h3 Heading

#### h4 Heading

##### h5 Heading

###### h6 Heading

Normal text after the headings.

---

## 2. Typographic Substitutions

### 2.1 Reference Table

| Sequence | Expected Result | Description |
|---|---|---|
| `(c)` / `(C)` | (c) / (C) | Copyright |
| `(r)` / `(R)` | (r) / (R) | Registered Mark |
| `(tm)` / `(TM)` | (tm) / (TM) | Trademark |
| `(p)` / `(P)` | (p) / (P) | Sound Recording Copyright |
| `(e)` / `(E)` | (e) / (E) | Euro |
| `(deg)` | (deg) | Degree |
| `(1/2)` | (1/2) | One Half |
| `(1/4)` | (1/4) | One Quarter |
| `(3/4)` | (3/4) | Three Quarters |
| `(x)` / `(X)` | (x) / (X) | Multiplication |
| `+-` | +- | Plus-Minus |
| `---` | --- | Em Dash |
| `--` | -- | En Dash |
| `...` | ... | Ellipsis |
| `->` | -> | Right Arrow |
| `<-` | <- | Left Arrow |
| `<=>` | <=> | Double Arrow |
| `=>` | => | Right Double Arrow |
| `<=` | <= | Left Double Arrow |
| `!=` / `/=` | != / /= | Not Equal |
| `>=` | >= | Greater or Equal |

### 2.2 Symbols in Running Text

Copyright (c) 2024 Acme Corp. (r) Registered Product (tm).

Price: 9.99 (e). Temperature: 37 (deg) C. Tip: (1/2) of the total.

Speed: 100 +- 5 km/h. Ratio: (3/4) parts. Dimensions: 3 (x) 4.

Flow: A -> B -> C. Tree: root <- leaf. Equivalence: A <=> B.

Implication: P => Q. Consequence: R <= S. Difference: a != b, x /= y.

Compare: value >= 10. Pipeline: step1 -- step2 --- step3.

Summary... more text.

### 2.3 Capitalization Variants

(c) (C) — both should produce ©

(r) (R) — both should produce ®

(tm) (tM) (Tm) (TM) — all should produce ™

(p) (P) — both should produce ℗

(e) (E) — both should produce €

(deg) (DEG) (Deg) — all should produce °

(x) (X) — both should produce ×

### 2.4 Chained Symbols (no spaces)

(c)(r)(tm) → ©®™ (three symbols in a row)

+-+- → ±+- (only the first pair)

----- → —-- (--- is consumed first, then -- becomes –)

....... → ……. (first ... → …, second ... → …, then .)

### 2.5 Ambiguous Sequences (edge cases in substitution order)

`-->` (outside code): --> — the `--` is consumed → `–>` (EXPECTED: –>)

`<--` (outside code): <-- — the `<-` should be seen first..., result: <--

`==>` (outside code): ==> — the `=>` is consumed → `=⇒` (EXPECTED: =⇒)

`<==` (outside code): <== — the `<=` is consumed → `⇐=` (EXPECTED: ⇐=)

`!==` (outside code): !== — the `!=` is consumed → `≠=` (EXPECTED: ≠=)

`->->` (double arrow): ->-> — both `->` are consumed → `→→`

`<-<-` (double arrow): <-<- → `←←`

### 2.6 Symbols in Headings

#### Copyright (c) in h4 — should show ©

##### Version 2.0 -- Updates (tm) — dash and mark

### 2.7 Symbols Inside Emphasis

**Bold with copyright (c) inside** → the symbol should be substituted.

*Italic with arrow -> inside* → should be substituted.

~~Strikethrough with em-dash --- here~~ → should be substituted.

**_(c) Combo bold+italic_** → should be substituted.

### 2.8 Symbols in Blockquotes

> Text with (c) 2024 and -> direction and +- tolerance inside a quote.
>
> Second line with -- long dash and ... ellipsis.

### 2.9 Symbols in Table Cells

| Description | Price | Note |
|---|---|---|
| Item (c) | 9,99 (e) | Stock +- 10 |
| Item (r) | price >= min | info -> see page |
| A => B | C <=> D | E != F |

### 2.10 Symbols in List Items

- Copyright (c) in non-ordered list item.
- Arrow -> in item. Temperature of 20 (deg).
- Price +- 5 (e). Fraction: (1/2).

1. (tm) in numbered item.
2. Dashes: a -- b --- c.
3. (r) mark at the end (r)

### 2.11 Symbols INSIDE Code — should NOT be substituted

Inline: `(c)` `(r)` `(tm)` `->` `<-` `<=>` `=>` `<=` `!=` `>=` `--` `---` `...` `+-`

Fenced block (the symbols below should NOT be substituted):

```
(c) (r) (tm) (p) (e) (deg) (1/2) (1/4) (3/4) (x)
+- --- -- ... -> <- <=> => <= != >= /=
```

```js
// JS Code — no substitutions should occur here
const url = "https://example.com?a>=1&b!=2";
const arrow = (x) => x * 2;   // (x) Must not be ×
const dash = "em--dash";       // -- Must not be –
if (a !== b) return a -> b;    // -> Must not be →
```

### 2.12 Warning: 4-space Indented Code Blocks

Code blocks indented with 4 spaces are NOT protected by the preprocessor.
Symbols inside WILL be substituted:

    (c) -> (r)  ← ATENTION: these are substituted (known limitation)
      -- and --- are also substituted here

Always use backtick fences (```) for code blocks.

### 2.13 Warning: Tilde Fences (~~~)

Tilde fences `~~~` are also not protected by the preprocessor:

~~~
(c) -> this is going to be substituted, even inside a tilde fence block (known limitation)
~~~

### 2.14 Symbols in Link URLs (edge case)

[Normal link](https://example.com) — no symbols in URL → OK.

[Link with >= in URL](https://example.com?count>=5) — the `>=` in the URL will be substituted → broken URL.

[Link with -> in href](https://example.com/path->file) — the `->` will be substituted → broken URL.

For URLs with those characters use HTML entities in markdown or reference links:

<https://example.com?a%3E%3D5>

### 2.15 Explicit HTML Entities (not processed by applySymbols)

&copy; &reg; &trade; &plusmn; &hellip; &mdash; &ndash;
&rarr; &larr; &hArr; &rArr; &lArr; &ne; &ge; &le;
&euro; &deg; &frac12; &frac14; &frac34; &times;

---

## 3. Horizontal Rules

The following three lines should produce horizontal lines,
not dashes (em dash):

___

---

***

**Note:** The line `---` is converted into a horizontal rule because markdown treats it as a section separator when it stands alone on its line.
However, `---` in the middle of a paragraph → --- (em dash).

---

## 4. Emphasis

### 4.1 Bold and Italic Basics

**bold with asterisks**

__bold with underscores__

*italic with asterisks*

_italic with underscores_

***bold and italic together***

___bold and italic together___

~~strikethrough~~

### 4.2 Emphasis Edge Cases

Underscore in middle_of_word — should not italicize (gfm).

Asterisk in middle*of*word — does italicize.

**bold at start of line** normal text continued.

normal text **bold in middle** more normal text.

_emphasis with **bold nested** inside_.

**bold with _italic nested_ inside**.

Text with `code` mixed with **bold** and _italic_.

### 4.3 Emphasis with Special Characters Adjacent

**(c)** — symbol in bold → should be **©**

_(tm)_ — symbol in italic → should be _™_

~~(r)~~ — symbol strikethrough

**->** — arrow in bold → **→**

---

## 5. Blockquotes

### 5.1 Basic

> A simple quote.

### 5.2 Nested Quotes

> Level 1
>> Level 2
>>> Level 3
>> Back to level 2
> Back to level 1

### 5.3 Quote with Multiple Paragraphs

> First paragraph of the quote.
>
> Second paragraph of the same quote.

### 5.4 Quote with Other Elements

> **Bold** and _italic_ inside a quote.
>
> - List inside quote
> - Another item
>
> | col1 | col2 |
> |---|---|
> | a | b |
>
> ```js
> const x = 1; // code in quote
> ```

### 5.5 Quote with Symbols

> Quote with (c) 2024 and -> direction and +- tolerance.

---

## 6. Lists

### 6.1 Unordered List Basic

- Item with dash
* Item with asterisk
+ Item with plus

### 6.2 Unordered Nested

- Level 1
  - Level 2
    - Level 3
      - Level 4
  - Back to level 2
- Back to level 1

### 6.3 Unordered with Marker Change

+ List with `+`, `-` or `*`
+ Sublists with 2 spaces of indentation:
  - Changing the marker forces new list:
    * Ac tristique libero volutpat at
    + Facilisis in pretium nisl aliquet
    - Nulla volutpat aliquam velit
+ Very easy!

### 6.4 Ordered List Basic

1. First item
2. Second item
3. Third item

### 6.5 Ordered with Repeated Numbers

1. You can use sequential numbers...
1. ...or keep all numbers as `1.`
1. The parser automatically numbers them.

### 6.6 Ordered Starting with Offset

57. foo
1. bar
1. baz

### 6.7 Ordered Nested

1. Lorem ipsum dolor sit amet
2. Lorem ipsum dolor sit amet
    1. Lorem ipsum dolor sit amet
    1. Lorem ipsum dolor sit amet
    1. Lorem ipsum dolor sit amet
        1. Lorem ipsum dolor sit amet
        3. Lorem ipsum dolor sit amet
        3. Lorem ipsum dolor sit amet
        3. Lorem ipsum dolor sit amet
3. Lorem ipsum dolor sit amet

### 6.8 Ordered Nested with Inserted Code

1. Lorem ipsum dolor sit amet
2. Lorem ipsum dolor sit amet
    1. Lorem ipsum dolor sit amet
       ```
       Sample code here... (c) -> should NOT be substituted
       ```
    1. Lorem ipsum dolor sit amet
    1. Lorem ipsum dolor sit amet
        1. Lorem ipsum dolor sit amet
        3. Lorem ipsum dolor sit amet
           | Markdown text | Result |
           |---|---|
           | `(c)` / `(C)` | © |
        3. Lorem ipsum dolor sit amet
        3. Lorem ipsum dolor sit amet
3. Lorem ipsum dolor sit amet

### 6.9 List with Paragraphs (loose list)

- Item with separate paragraph.

  Additional paragraph of same item, indented 2 spaces.

- Next item.

  > Quote inside a list item.

### 6.10 List with Symbols in Items

- Copyright (c) 2024 — should be ©
- Temperature 100 (deg) C — should be °
- Price: 10 (e) +- 0,5 — should be € and ±
- Path: start -> end — should be →
- Range: min <= max — should be ⇐ (**note:** `<=` is ⇐, not ≤)

---

## 7. Code

### 7.1 Inline Code

Inline: `code between backticks` — the symbols `(c) -> --` are NOT substituted.

### 7.2 Indented Block (4 spaces)

    // WARNING: symbols here WILL be substituted (preprocessor limitation)
    const x = foo -> bar;
    // (c) 2024 Acme

### 7.3 Block with Fences (```)

```
Text without language.
Symbols NOT substituted: (c) -> -- --- ... (tm) +-
```

### 7.4 Syntax Highlighting

```js
var arrow = (x) => x + 1;  // (x) and => are NOT substituted inside the block
var dash  = "a--b";        // -- is NOT substituted
var gt    = a >= b;        // >= is NOT substituted
```

```python
# Python
def fn(x):
    return x -> y  # (c) comment
```

```css
/* CSS */
.el::before {
  content: "(c) 2024";  /* not substituted */
}
```

### 7.5 Nested Backtick in Inline Code

Use `` ` `` for a literal backtick inside inline code.

`` `(c)` `` — double backtick protects the content.

### 7.6 Tilde Fences (~~~) — known limitation

~~~
(c) -> this block is NOT protected by applySymbols
Symbols WILL be substituted here.
~~~

---

## 8. Tables

### 8.1 Simple Table

| Option | Description |
|--------|-------------|
| data   | path to data files |
| engine | template engine |
| ext    | extension for destinations |

### 8.2 Column Alignment

| Left | Center | Right |
|:----------|:--------:|--------:|
| text     | text    | text   |
| aligned  | aligned | aligned |

### 8.3 Minimal Separator

| A | B |
|---|---|
| 1 | 2 |

### 8.4 Separator with Asymmetric Spacing

| A | B |
| ------ | ----------- |
| x | y |

### 8.5 Separator with Mixed Alignment

| A | B | C |
|:--|:--:|--:|
| lft | ctr | rgt |

### 8.6 Symbols in Table Cells (should be substituted)

| Symbol | Input | Output |
|---|---|---|
| Copyright | (c) | © |
| Registered | (r) | ® |
| Trademark | (tm) | ™ |
| Em dash | --- | — |
| En dash | -- | – |
| Arrow | -> | → |
| Plus-minus | +- | ± |
| Greater-equal | >= | ≥ |
| Not-equal | != | ≠ |

### 8.7 Code in Table Cells (should NOT be substituted)

| Expression | Meaning |
|---|---|
| `(c)` | copyright raw |
| `->` | arrow raw |
| `>=` | gte raw |
| `a !== b` | strict not equal |

### 8.8 Table with Dashes in Content (edge case)

| Name | Range | Note |
|---|---|---|
| option A | 10 -- 20 | valid |
| option B | 20 --- 30 | valid |
| --- | separator | ← this is content, not separator |
| \|pipe\| | in cell | escaped |

### 8.9 Table Inside List (indented)

1. Item before table
2. Item with nested table:

   | Col1 | Col2 |
   |---|---|
   | a | b (c) |
   | -> | >= |

3. Item after table

---

## 9. Links

### 9.1 Basic

[link text](https://example.com)

[link with title](https://example.com "Link Title")

### 9.2 Reference Link

[text][ref-id]

[ref-id]: https://example.com "Optional Title"

### 9.3 Autolink

<https://example.com>

<user@example.com>

### 9.4 Internal Wiki Path

[See syntax page](syntax)

[See docs overview](docs/overview)

[Relative path with sublevel](docs/guide/intro)

### 9.5 Edge Case — Symbols in URL

The following links may break because the preprocessor substitutes
symbols BEFORE marked processes the URLs:

[URL with >= ](https://example.com?a>=1) ← `>=` in href becomes `≥`, URL broken.

[URL with -> ](https://example.com/path->file) ← `->` becomes `→`, URL broken.

Solution: use URL-encoded links:

[Safe URL](https://example.com?a%3E%3D1)

---

## 10. Images

### 10.1 Basic

![Alt text](https://octodex.github.com/images/minion.png)

![With title](https://octodex.github.com/images/stormtroopocat.jpg "The Stormtroopocat")

### 10.2 By Reference

![Alt text][img-id]

[img-id]: https://octodex.github.com/images/dojocat.jpg "The Dojocat"

### 10.3 Image with Symbols in Alt Text

![Icon (c) 2024](https://octodex.github.com/images/minion.png)

---

## 11. HTML Entities

HTML entities are passed directly to the browser without modification:

&copy; &reg; &trade; &euro; &deg; &frac12; &frac14; &frac34; &times;

&plusmn; &mdash; &ndash; &hellip; &rarr; &larr; &hArr; &rArr; &lArr;

&ne; &ge; &le; &amp; &lt; &gt; &quot; &apos;

### 11.1 Numeric Entities

&#169; (©) &#174; (®) &#8482; (™) &#8364; (€) &#177; (±)

&#8212; (—) &#8211; (–) &#8230; (…) &#8594; (→) &#8592; (←)

---

## 12. Inline HTML

The parser (marked) allows inline HTML without escaping:

<kbd>Ctrl</kbd> + <kbd>S</kbd> to save.

<mark>Text highlighted with mark</mark>

<abbr title="HyperText Markup Language">HTML</abbr>

<del>Deleted text</del> and <ins>inserted text</ins>

<sup>superscript</sup> and <sub>subscript</sub>

<small>Small text</small> and <b>bold HTML</b>

Symbol in raw HTML: <span>(c) 2024</span> — the `(c)` WILL be substituted (preprocessor acts first).

---

## 13. Escaped Characters

Backslash escapes special markdown characters:

\*no italic\* \*\*no bold\*\* \~\~no strikethrough\~\~

\# Not a heading

\- Not a list item

\[Not a link\](https://example.com)

\`Not code\`

\| Not breaking table \|

### 13.1 Escape Symbol Sequences

There is no way to escape the substitutions of `applySymbols` in normal text
(use inline code instead):

`(c)` → not substituted

`->` → not substituted

`--` → not substituted

---

## 14. Complex Combinations

### 14.1 Table with Links and Code

| Name | Link | Code |
|---|---|---|
| Project (c) | [see](https://example.com) | `x -> y` |
| Version (r) | [docs](docs/overview) | `a >= b` |

### 14.2 List with Quotes, Code, and Table

1. Normal item with (c)
2. Item with quote:
   > This is a quote with -> arrow and -- dash.
3. Item with code: `(tm)` not substituted.
4. Item with table:

   | a | b |
   |---|---|
   | (c) | -> |

5. Final item.

### 14.3 Blockquote with List and Table Inside

> **List inside quote:**
>
> - Item (c) with copyright
> - Item (r) with registered
>
> **Table inside quote:**
>
> | A | B |
> |---|---|
> | (tm) | -> |

### 14.4 Code in List in Table

| Step | Command | Note |
|---|---|---|
| 1 | `npm install` | dependencies |
| 2 | `(x) => x + 1` | NOT substituted in code |
| 3 | build -> deploy | IS substituted outside code |

### 14.5 Deep Nesting with All Elements

1. Level 1 with (c)
   - Level 2 with ->
     - Level 3 with --
       1. Level 4 with (tm)
          > Quote in level 4 with +-
          >
          > ```
          > code in quote in list (c) -> NOT substituted
          > ```
       2. Table in level 4:

          | x | y |
          |---|---|
          | >= | (r) |

---

## 15. Preprocessor Precedence Rules

The preprocessor applies substitutions in this order (important for overlapping sequences):

1. `---` before `--` (otherwise `---` would give `–-` instead of `—`)
2. `<=>` before `<=` and `=>`
3. `=>` before `>=` (no overlap, but order documented)
4. Parentheses `(c)` etc. are processed after punctuation operators

Precedence test cases:

| Sequence | Real Result | Expected Result | OK? |
|---|---|---|---|
| `---` | --- | — | ✓ |
| `----` | ---- | —- | ✓ (--- → —, left -) |
| `-----` | ----- | —– | ✓ (--- → —, -- → –) |
| `------` | ------ | —— | ✓ (--- → — twice) |
| `<=>` | <=> | ⇔ | ✓ |
| `<==` | <== | ⇐= | ✓ (<= → ⇐) |
| `==>` | ==> | =⇒ | ✓ (=> → ⇒) |
| `-->` | --> | –> | ✓ (-- → –) |
| `<--` | <-- | ←- | ✓ (<- → ←) |

---

## 16. Table Separator Rows — Edge Cases

The preprocessor skips lines that only contain `|`, `-`, `:`, and spaces.
The following are all valid separators and should NOT be modified:

| x | y | z |
|---|---|---|
| a | b | c |

| x | y | z |
| -- | -- | -- |
| a | b | c |

| x | y | z |
|:--|:--:|--:|
| a | b | c |

| x | y | z |
| :--- | :---: | ---: |
| a | b | c |

Indented separator (inside list):

- List
  - Sub-list with table:

    | col1 | col2 |
    |------|------|
    | val1 | val2 |

---

## 17. Merged Cells in Tables

**Note:** Standard Markdown does not natively support merged cells (colspan/rowspan).
The following examples use inline HTML `<table>` elements to demonstrate merged cells.

### 17.1 Row Spans (colspan)

<table>
<tr>
  <th colspan="3">Merged Header (3 columns)</th>
</tr>
<tr>
  <td>Col 1</td>
  <td>Col 2</td>
  <td>Col 3</td>
</tr>
</table>

### 17.2 Column Spans (rowspan)

<table>
<tr>
  <th rowspan="3">Category</th>
  <th>Q1</th>
  <td>100</td>
</tr>
<tr>
  <th>Q2</th>
  <td>150</td>
</tr>
<tr>
  <th>Q3</th>
  <td>200</td>
</tr>
</table>

### 17.3 Complex Merged Cells

<table>
<tr>
  <th colspan="2" rowspan="2">Project</th>
  <th colspan="2">Progress</th>
</tr>
<tr>
  <th>Done</th>
  <th>Pending</th>
</tr>
<tr>
  <td rowspan="2">Phase 1</td>
  <td>Design</td>
  <td>100%</td>
  <td>0%</td>
</tr>
<tr>
  <td>Dev</td>
  <td>75%</td>
  <td>25%</td>
</tr>
<tr>
  <td colspan="2">Phase 2</td>
  <td>50%</td>
  <td>50%</td>
</tr>
</table>

### 17.4 Matrix with Axis Labels (rowspan + colspan)

<table>
<tr>
  <th colspan="1" rowspan="1"></th>
  <th>2024</th>
  <th>2025</th>
  <th>2026</th>
</tr>
<tr>
  <th>Sales</th>
  <td>50k</td>
  <td>75k</td>
  <td>100k</td>
</tr>
<tr>
  <th>Costs</th>
  <td>30k</td>
  <td>35k</td>
  <td>40k</td>
</tr>
<tr>
  <th>Profit</th>
  <td>20k</td>
  <td>40k</td>
  <td>60k</td>
</tr>
</table>

### 17.5 Table with Mixed Styling and Merged Cells

<table border="1" cellpadding="10">
<tr style="background-color: #f0f0f0;">
  <th colspan="4">Product Catalog</th>
</tr>
<tr>
  <th>Name</th>
  <th colspan="2">Price by Region</th>
  <th>Stock</th>
</tr>
<tr>
  <td>Widget A</td>
  <td>Europe: €50</td>
  <td>USA: $60</td>
  <td rowspan="3" style="text-align: center;"><strong>In Stock</strong></td>
</tr>
<tr>
  <td>Widget B</td>
  <td colspan="2">Special pricing available</td>
</tr>
<tr>
  <td>Widget C</td>
  <td>Europe: €45</td>
  <td>USA: $55</td>
</tr>
</table>

### 17.6 Limitation Notes

- **Standard Markdown:** Does not support `colspan` or `rowspan`.
- **Workaround 1:** Use inline HTML `<table>` elements (shown above).
- **Workaround 2:** For simple layouts, use separate tables or reorganize data.
- **Workaround 3:** Use Markdown variants (e.g., Pandoc extended tables) if supported by parser.

Example of using separate Markdown tables as alternative:

**Sales by Region:**

| Region | 2024 | 2025 | 2026 |
|--------|------|------|------|
| Europe | 20k  | 30k  | 40k  |
| USA    | 30k  | 45k  | 60k  |

## 18. Icons and Emoji

The wiki has no *shortcode* system (`:smile:` etc.), but it accepts emoji
and Unicode symbols in three ways:

1. **Direct entry** — paste a UTF-8 character straight into the text.
2. **Numeric HTML entities** — `&#NNNN;` (decimal) or `&#xHHHH;` (hex).
3. **Named HTML entities** — `&name;` recognised by the browser.

The file is stored as UTF-8, so any Unicode character is valid.

### 18.1 Emoji directly in text

These are Unicode characters pasted directly into Markdown:

😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😎 🥳 😴 🤔 🤗

😢 😭 😤 😠 😡 🤬 😱 😰 😨 🤯 🥶 🥵 🫠 😵 🤪 🤩 🥸 🤓 👻 💀

👍 👎 👏 🙌 🤝 👋 ✌️ 🤞 🫶 ❤️ 🧡 💛 💚 💙 💜 🖤 🤍 ❤️‍🔥 💔 💯

🌍 🌎 🌏 🌐 🗺️ 🏔️ 🏕️ 🌋 🗻 🏠 🏢 🏣 🏤 🏥 🏦 🏧 🏨 🏩 🏪 🏫

🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🐔 🐧 🐦 🦆 🦅

🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🫒 🥑 🍆

🚀 🛸 🛩️ ✈️ 🚂 🚃 🚄 🚅 🚆 🚇 🚈 🚉 🚊 🚝 🚞 🚋 🚌 🚍 🚎 🚐

⌚ ⌛ ⏰ ⏱️ ⏲️ 🕰️ ⏳ 📅 📆 📇 📈 📉 📊 📋 📌 📍 📎 🖇️ 📏 📐

### 18.2 Classic Unicode symbols (non-emoji)

Arrows and mathematical operators, also available via `applySymbols`:

| Character | HTML Entity | Description |
|---|---|---|
| ← | `&larr;` | Left arrow |
| → | `&rarr;` | Right arrow |
| ↑ | `&uarr;` | Up arrow |
| ↓ | `&darr;` | Down arrow |
| ↔ | `&harr;` | Left-right arrow |
| ⇐ | `&lArr;` | Left double arrow |
| ⇒ | `&rArr;` | Right double arrow |
| ⇔ | `&hArr;` | Double implication |
| ± | `&plusmn;` | Plus-minus |
| × | `&times;` | Multiplication |
| ÷ | `&divide;` | Division |
| ≠ | `&ne;` | Not equal |
| ≤ | `&le;` | Less than or equal |
| ≥ | `&ge;` | Greater than or equal |
| ≈ | `&asymp;` | Approximately equal |
| ∞ | `&infin;` | Infinity |
| √ | `&radic;` | Square root |
| ∑ | `&sum;` | Summation |
| ∏ | `&prod;` | Product |
| ∫ | `&int;` | Integral |
| ° | `&deg;` | Degree |
| ½ | `&frac12;` | One half |
| ¼ | `&frac14;` | One quarter |
| ¾ | `&frac34;` | Three quarters |
| © | `&copy;` | Copyright |
| ® | `&reg;` | Registered trademark |
| ™ | `&trade;` | Trademark |
| € | `&euro;` | Euro |
| £ | `&pound;` | Pound sterling |
| ¥ | `&yen;` | Yen |
| § | `&sect;` | Section |
| ¶ | `&para;` | Paragraph |
| † | `&dagger;` | Dagger |
| ‡ | `&Dagger;` | Double dagger |
| • | `&bull;` | Bullet |
| … | `&hellip;` | Ellipsis |
| — | `&mdash;` | Em dash |
| – | `&ndash;` | En dash |
| " | `&ldquo;` | Left double quotation mark |
| " | `&rdquo;` | Right double quotation mark |
| ' | `&lsquo;` | Left single quotation mark |
| ' | `&rsquo;` | Right single quotation mark |

### 18.3 Card suits, circled numbers, and geometric shapes

&#9824; &#9827; &#9829; &#9830; (♠ ♣ ♥ ♦) — card suits via numeric entity.

&#9312; &#9313; &#9314; &#9315; &#9316; (① ② ③ ④ ⑤) — circled numbers.

&#9632; &#9633; &#9650; &#9651; &#9670; &#9671; (■ □ ▲ △ ◆ ◇) — geometric shapes.

### 18.4 Status and check symbols

| Symbol | Entity / Unicode | Common use |
|---|---|---|
| ✓ | `&#10003;` | Verified / OK |
| ✗ | `&#10007;` | Error / Failure |
| ✔ | `&#10004;` | Heavy check mark |
| ✘ | `&#10008;` | Heavy ballot X |
| ⚠ | `&#9888;` | Warning |
| ℹ | `&#8505;` | Information |
| 🔴 | `&#128308;` | Red status / error |
| 🟡 | `&#128993;` | Yellow status / warning |
| 🟢 | `&#128994;` | Green status / OK |
| ⭐ | `&#11088;` | Star |
| 🔒 | `&#128274;` | Locked |
| 🔓 | `&#128275;` | Unlocked |

### 18.5 Emoji in special positions

**Bold:** 🚀 **Launch**

*Italic:* 🤔 *Pending review*

~~Strikethrough:~~ ~~❌ Discarded~~

> 💡 Tip: emoji also work inside blockquotes.

- ✅ Task completed
- ❌ Task cancelled
- ⏳ In progress
- 📌 Pending

| Status | Icon | Description |
|---|---|---|
| OK | ✅ | Completed without errors |
| Error | ❌ | Failure detected |
| Warning | ⚠️ | Requires attention |
| Info | ℹ️ | Additional information |
| New | 🆕 | Recently added |
| Locked | 🔒 | No access |

### 18.6 Note on ODT export

Emoji and Unicode symbols are included in the exported `.fodt` file as
UTF-8 text. Correct rendering depends on whether the font used by the
word processor (LibreOffice, Word) includes the corresponding glyphs;
otherwise the substitution character □ will appear.

Symbols from §17.2 (named/numeric HTML entities) are resolved by the
browser and reach the exporter as Unicode characters, so they are also
included correctly in the ODT.
