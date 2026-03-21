# GTW (Generating The Web) — Syntax Reference

Source: `gtw-help.chm` from `C:\Program Files (x86)\Generating The Web\`

This document is the complete syntax specification of the original GTW desktop engine, which serves as the canonical reference for the Spintax WordPress plugin syntax.

---

## 1. Enumerations `{ }`

Randomly selects ONE option from the list.

```text
{option1|option2|option3}
```

**Examples:**

```text
{blue|grey|clear}
{|online|internet} casino        ← empty option = sometimes nothing
{1X{S|s}lots}                    ← nested enumerations
{license {|#8048} from Curacao}  ← nesting with empty option
```

**Rules:**
- Delimiters: `{` and `}`
- Separator: `|`
- Supports nesting to arbitrary depth
- Empty options are valid (produce empty string)
- Resolution is from the innermost expression outward

---

## 2. Permutations `[ ]`

Selects N elements, shuffles them, and joins with separators.

### 2.1 Simple Permutations

All elements included, space-separated:

```text
[1|2|3|4]
```

Output examples: `1 4 3 2`, `2 3 4 1`, `3 2 4 1`

### 2.2 Single Separator

Uniform separator specified in `< >` at the start:

```text
[<, > 1|2|3|4]
```

Output examples: `2, 1, 4, 3`, `4, 3, 2, 1`

**Important:** No space between `[` and `<separator>`.

### 2.3 Different Separators Per Element

Each option can have its own separator defined with `<sep>` before the preceding `|`:

```text
[<, > 1|2|3 < and >|4]
```

Output examples: `1, 3, 2 and 4`, `3, 1, 2 and 4`

### 2.4 Permutations with Combinations

Configurable min/max element count and separators:

```text
[<minsize=1;maxsize=3;sep=", ";lastsep=" and "> apple|plum|orange|apricot]
```

Output examples: `apple, plum and orange`, `apple and apricot`, `orange`

**Configuration parameters:**

| Parameter | Default           | Description                         |
|-----------|-------------------|-------------------------------------|
| `minsize` | count of all      | Minimum number of elements to pick  |
| `maxsize` | count of all      | Maximum number of elements to pick  |
| `sep`     | `" "` (space)     | Separator between non-final items   |
| `lastsep` | same as `sep`     | Separator before the last element   |

**Rules:**
- Delimiters: `[` and `]`
- Separator: `|`
- Config block `<...>` must immediately follow `[`
- Config parameters are semicolon-separated
- String values in config are quoted: `sep=", "`
- Supports nesting: enumerations and permutations can appear inside options
- HTML elements can be options: `[<minsize=3;> <li>item1</li>|<li>item2</li>|<li>item3</li>]`

---

## 3. Variable Macros `#set`

Defines a reusable variable that is substituted wherever it appears.

```text
#set %VARIABLE_NAME% = value or spintax structure
```

**Examples:**

```text
#set %name% = John
#set %greeting% = {Hello|Hi|Hey}
#set %items% = [<minsize=2;maxsize=3;sep=", ";lastsep=" and "> apples|oranges|bananas]
Some text with %name% and %greeting%, also %items%.
```

**Rules:**
- `#set` must start at the beginning of a line
- Variable names are enclosed in `%`: `%name%`
- Variable names are alphanumeric + underscore
- Values can contain any spintax syntax (enumerations, permutations, other variables)
- Variables are expanded when referenced, not when defined (lazy evaluation)
- `#set` lines are stripped from output
- Variables can reference other variables (expanded recursively)

---

## 4. Constant Macros `#const`

Defines correlated selection sets — parallel arrays where the same index is picked across all constant groups.

```text
#const = {white|ruler}
#const = {beautiful|red}
#const = {cat|gold}

Text with %const%[1] and %const%[2] and %const%[3].
```

If index 1 is selected: `white`, `beautiful`, `cat`
If index 2 is selected: `ruler`, `red`, `gold`

**Rules:**
- Multiple `#const` lines define parallel arrays
- All constants in a group share the same randomly selected index
- Referenced as `%const%[N]` where N is the constant's position (1-based)
- Values can contain spintax syntax

**Note:** Not implemented in Spintax WP plugin v1. Same effect can be partially achieved with variables, though true index correlation requires the `#const` mechanism.

---

## 5. Include Directive `#include`

Includes content from an external file.

```text
#include "C:\path\to\file.txt"
```

**Rules:**
- File path must be in double quotes
- Included file is inserted at the directive's position
- Included files can contain their own variables, constants, and spintax
- Recursive includes are supported

**Spintax WP plugin:** Supported in v1 as GTW-compatible alias for nested templates. `#include "hero-text"` resolves as template slug (or numeric ID). Does not support passing variables — use `[spintax slug="..." var="val"]` for parameterized embedding.

---

## 6. Comments `/#...#/`

Text between comment markers is completely ignored during generation.

```text
/#
  This is a comment section.
  It can span multiple lines.
  It won't appear in output.
#/
```

**Rules:**
- Start delimiter: `/#`
- End delimiter: `#/`
- Can span multiple lines
- Cannot be nested
- Removed before any other processing

**Common practice:** Templates also use HTML-style section markers for visual organization:

```text
<--// Section Title //-->
```

These are not a GTW feature but are commonly used in templates and are stripped by browsers in HTML output.

---

## 7. Synonym Dictionaries

GTW supports up to 5 external synonym dictionaries (thesaurus files).

- Words can be automatically replaced with synonyms during generation
- Supports case-sensitive and case-insensitive replacement modes
- Managed through the desktop application UI

**Note:** Not implemented in Spintax WP plugin. Desktop-only feature.

---

## 8. Shingles

Text repetition filtering mechanism.

- Prevents repetition of specific n-gram patterns
- Filters at paragraph or document level
- Supports whitelist/blacklist of patterns

**Note:** Not implemented in Spintax WP plugin. Desktop-only feature.

---

## 9. Links

Associates URLs with keyword sets for automatic anchor text generation.

```text
http://www.example.com [keyword1; keyword2; keyword3]
www.site.com [beautiful; date; line]
```

**Rules:**
- URL followed by keywords in square brackets
- Keywords separated by semicolons `;`
- URL can include or omit protocol

**Note:** Not implemented as dedicated syntax in Spintax WP plugin. Same result achievable via variables:

```text
#set %link% = <a href="http://example.com">{keyword1|keyword2|keyword3}</a>
```

---

## 10. Nesting Rules

All syntax elements can be nested within each other:

- Enumerations `{}` inside enumerations `{}`
- Permutations `[]` inside enumerations `{}`
- Enumerations `{}` inside permutations `[]`
- Variables inside any structure
- Arbitrary nesting depth

**Examples:**

```text
{option1|[<, > sub1|sub2|sub3]|option3}
[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> {red|blue} apples|{big|small} oranges|bananas]
#set %var% = {a|[b|c]}
```

---

## 11. Post-Processing (Text Correction)

GTW applies automatic text correction after generation:

- Fix spacing around punctuation (remove space before `,`, `.`, `!`, `?`)
- Add space after punctuation where missing
- Collapse multiple consecutive spaces
- Capitalize first letter after sentence-ending punctuation
- Capitalize after line breaks where appropriate
- Preserve domains and decimal numbers during capitalization

---

## 12. Syntax Summary

| Feature         | Syntax                    | Behavior                      | Status in WP Plugin |
|-----------------|---------------------------|-------------------------------|---------------------|
| Enumeration     | `{a\|b\|c}`              | Pick one random option        | v1                  |
| Permutation     | `[a\|b\|c]`              | Pick N, shuffle, join         | v1                  |
| Single sep      | `[<sep> a\|b\|c]`        | Permutation, uniform sep      | v1                  |
| Per-element sep | `[<,> a\|b <x>\|c]`      | Permutation, custom seps      | v1                  |
| Combinations    | `[<config> a\|b\|c]`     | Permutation with min/max      | v1                  |
| Variable        | `#set %var% = val`        | Reusable substitution         | v1                  |
| Comment         | `/#...#/`                 | Stripped from output           | v1                  |
| Constant        | `#const = {a\|b}`        | Correlated parallel selection  | Post-v1             |
| Include         | `#include "path"`         | Insert external file           | v1 (slug/ID-based)  |
| Links           | `URL [kw1; kw2]`         | Auto anchor text               | Use variables       |
| Synonyms        | (UI-managed)              | Auto word replacement          | Not planned         |
| Shingles        | (UI-managed)              | Repetition filtering           | Not planned         |
