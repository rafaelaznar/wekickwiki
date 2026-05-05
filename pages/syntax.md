# Markdown Syntax

## Headings

```
# H1
## H2
### H3
```

## Emphasis

```
**bold**   *italic*   ~~strikethrough~~
```

## Lists

```
- Item
- Another
  - Sub-item

1. First
2. Second
```

## Links

```
[Label](page)                  ← internal relative link
[External](https://example.com)
```

Relative links are resolved from the current page's path. Use `../` to go up a level.

## Code

```
`inline code`

~~~
code block
~~~
```

```javascript
// code block with language for syntax highlighting
function hello() {
  console.log("Hello, world!");
}
```

## Blockquotes

```
> A block quote
```

## Tables

```
| Column 1 | Column 2 |
|----------|----------|
| cell     | cell     |
```

## Images

```
![alt text](path/image.png)
```

## Horizontal rule

```
---
```

---

## Syntax highlight examples

### JavaScript

```javascript
// Fibonacci with memoization
function fib(n, memo = {}) {
  if (n in memo) return memo[n];
  if (n <= 1) return n;
  return (memo[n] = fib(n - 1, memo) + fib(n - 2, memo));
}

console.log([...Array(10).keys()].map(fib));
```

### Python

```python
from functools import lru_cache

@lru_cache(maxsize=None)
def fib(n: int) -> int:
    if n <= 1:
        return n
    return fib(n - 1) + fib(n - 2)

print([fib(i) for i in range(10)])
```

### PHP

```php
<?php
function fib(int $n, array &$memo = []): int {
    if ($n <= 1) return $n;
    if (isset($memo[$n])) return $memo[$n];
    return $memo[$n] = fib($n - 1, $memo) + fib($n - 2, $memo);
}

echo implode(', ', array_map('fib', range(0, 9)));
```

### Java

```java
import java.util.HashMap;
import java.util.Map;

public class Fib {
    private static final Map<Integer, Long> memo = new HashMap<>();

    public static long fib(int n) {
        if (n <= 1) return n;
        return memo.computeIfAbsent(n, k -> fib(k - 1) + fib(k - 2));
    }

    public static void main(String[] args) {
        for (int i = 0; i < 10; i++) System.out.print(fib(i) + " ");
    }
}
```

### Bash

```bash
#!/usr/bin/env bash
fib() {
  local n=$1
  (( n <= 1 )) && echo $n && return
  echo $(( $(fib $((n-1))) + $(fib $((n-2))) ))
}

for i in $(seq 0 9); do printf '%s ' "$(fib $i)"; done
echo
```

### SQL

```sql
-- Recursive CTE: first 10 Fibonacci numbers
WITH RECURSIVE fib(n, a, b) AS (
  SELECT 0, 0, 1
  UNION ALL
  SELECT n + 1, b, a + b FROM fib WHERE n < 9
)
SELECT n, a AS value FROM fib ORDER BY n;
```

### CSS

```css
/* Dark code block override */
#content pre {
  background: #1e1e1e;
  color: #d4d4d4;
  border-radius: 6px;
  padding: 1rem 1.25rem;
  overflow-x: auto;
  font-size: 0.875rem;
  line-height: 1.6;
}

#content pre code {
  background: none;
  padding: 0;
}
```

### JSON

```json
{
  "plugin": "syntax-highlight",
  "version": "1.0.0",
  "engine": "highlight.js",
  "languages": ["auto-detected"],
  "theme": "github",
  "hooks": ["wkw.page.afterLoad"],
  "cdn": "https://cdn.jsdelivr.net/npm/highlight.js"
}
```

---


[← Home](../index) · [About](../about)
