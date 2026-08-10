# Hardening-invariant families

Reference catalogue for the `symfony-security-review` skill. Each family lists:

- **Anchor**: how to find the sinks in scope (grep, run against the scope, not the whole tree).
- **Invariant / check**: the question to answer at every sink.
- **Automated coverage**: the sa-tools gate that already enforces it, if any.
- **Decision boundary**: shapes that look unsafe but are accepted. Do not flag these.

Families are grouped by how they are best checked.

---

## A. Gated by sa-tools (confirm the gate caught it; otherwise it is a finding)

### A1. Webhook signature/secret verification
- **Anchor**: `grep -rl "extends AbstractRequestParser" src --include=*.php | grep -v Tests`
- **Invariant**: a concrete `doParse(Request, #[\SensitiveParameter] string $secret)`
  must verify the request before returning an event: compare the signature with
  `hash_equals()` (never `===`/`!==`/`==`/`strcmp`), pin the HMAC algorithm to a
  literal (never read it from the request), and reject a missing signature.
- **Automated coverage**: `HardenedComparisonRule` (inline compare only),
  `check-hardening-tests.php` (requires a `RejectWebhookException` test).
- **Decision boundary**: gating verification behind `'' !== $secret` is accepted
  (an empty configured secret intentionally disables verification). Authenticating
  by IP allowlist (`IpsRequestMatcher`) instead of a secret is accepted.

### A2. `__unserialize()` `__toString` trampoline
- **Anchor**: `grep -rln "function __unserialize" src --include=*.php | grep -v Tests`
- **Invariant**: before assigning `$data[k]` to a string-typed property, reject
  object values (`instanceof \Stringable` / `is_object`), so a crafted payload cannot
  fire `__toString` during `unserialize()`.
- **Automated coverage**: `UnserializeToStringTrampolineRule`; `check-hardening-tests.php` part B.
- **Decision boundary**: int/float/bool slots do not trampoline (coercion throws first).
  A `__unserialize()` that blanket-throws is exempt.

### A3. `unserialize()` without `allowed_classes`
- **Anchor**: `grep -rn "unserialize(" src --include=*.php | grep -v Tests | grep -v allowed_classes`
- **Invariant**: `unserialize()` of data that is not provably first-party-trusted must
  pass `['allowed_classes' => false]` or an explicit allowlist.
- **Automated coverage**: `UnserializeMissingAllowedClassesRule`.
- **Decision boundary**: a literal `allowed_classes` on a first-party cache/container
  file is acceptable; the risk is untrusted bytes.
- **See also**: B15 (the Serializer/denormalize analogue), B2/B3 (nested `unserialize`,
  destructor gadgets).

---

## B. High-signal, partly static (worth a manual pass; some are buildable rules)

### B1. Verify-MAC-before-deserialize ordering  [highest severity]
- **Anchor**: serializer `decode()` and `doParse()` bodies that both deserialize and verify:
  `grep -rn "function decode\|function doParse" src --include=*.php | grep -v Tests`
- **Invariant**: no `unserialize()` / `$request->toArray()` / inner `decode()` may run
  before the `hash_equals()` / secret guard. Verify, then parse. Deserializing before
  verifying turns a signed transport into an unauthenticated deserialization sink.
- **Automated coverage**: none (verify-order is best asserted by a per-serializer
  regression test, not a static rule).

### B2. Nested `unserialize()` inside `__unserialize()`
- **Anchor**: in a `__unserialize` body, any raw `unserialize(` that is not `parent::__unserialize(`.
- **Invariant**: do not call `unserialize()` again inside `__unserialize()`; it
  re-opens the gadget surface the outer call already controls.
- **Automated coverage**: none yet (candidate extension of `UnserializeMissingAllowedClassesRule`).

### B3. Side-effecting `__destruct` / `__wakeup` as a POP gadget
- **Anchor**: `grep -rln "function __destruct\|function __wakeup" src --include=*.php | grep -v Tests`,
  then keep the classes that are still serializable (no throwing `__sleep`/`__serialize`/`__wakeup`).
- **Invariant**: a class whose `__destruct()`/`__wakeup()` has exploitable side effects
  (filesystem writes, `unlink`, process or network calls, cache flush) must be made
  non-unserializable, so it cannot be reached as a gadget when an untrusted `unserialize()`
  exists elsewhere. The accepted fix throws from `__sleep()`/`__serialize()`/`__wakeup()`.
  `Cache\Adapter\AbstractAdapter` and `TagAwareAdapter` are the canonical exemplars
  (they forbid serialization).
- **Automated coverage**: none yet (candidate easy AST rule: a non-trivial
  `__destruct`/`__wakeup` with a side-effecting call and no throwing serialization guard).
  Complements A3, which blocks instantiating arbitrary gadget classes, by hardening the
  gadget itself.
- **Decision boundary**: defense-in-depth, exploitable only together with an untrusted
  `unserialize()` entry, so usually hardening rather than a CVE on its own; the
  gadget-bearing class is still the high-value target. A `__destruct` that only releases
  in-memory state is not a gadget.

### B4. XXE / dangerous libxml
- **Anchor**: `grep -rn "validateOnParse\|->loadXML(\|LIBXML_NOENT\|LIBXML_DTDLOAD" src --include=*.php | grep -v Tests`
- **Invariant**: untrusted XML must not be parsed with `validateOnParse = true` or with
  `LIBXML_NOENT`/`LIBXML_DTDLOAD`; reject DOCTYPE, or disable external entities.
- **Automated coverage**: none yet (candidate easy AST rule). Distinguish `loadXML` (XML)
  from `loadHTML`.
- **Decision boundary**: a few XML helpers set `validateOnParse` but are guarded by a
  post-parse DOCTYPE-rejection loop; confirm the guard before flagging.

### B5. SSRF / private-network
- **Anchor**: `grep -rn "PRIVATE_SUBNETS\|NoPrivateNetworkHttpClient\|gethostbyname\|filter_var.*FILTER_VALIDATE_IP" src --include=*.php | grep -v Tests`
- **Invariant**: resolve the host, validate the resolved IP against the full reserved
  set (IPv4 + IPv6 transition forms + RFC6598), and pin the connection to it (no TOCTOU
  re-resolve). Strip credentials on cross-host redirects.
- **Automated coverage**: none (the subnet list is data; use a completeness test).
- **Decision boundary**: the subnet list is an answer-key; a static check only confirms a
  curated set, it cannot find the next missing range.

### B6. Open redirect / user-controlled redirect
- **Anchor**: `grep -rn "RedirectResponse\|createRedirectResponse\|'Location'\|\"Location\"" src --include=*.php | grep -v Tests`
- **Invariant**: a redirect target derived from request input must pass a host/path
  allowlist on every path (including forward and stateless), and reject backslash and
  scheme-relative `//host` tricks.
- **Automated coverage**: none (a taint problem; Psalm at best).

### B7. CRLF / control-char injection (headers, addresses, URIs)
- **Anchor**: `grep -rn "class Address\|ParameterizedHeader\|MailboxHeader\|->setUri\|getUri(" src/Symfony/Component/Mime src/Symfony/Component/HttpFoundation --include=*.php | grep -v Tests`
- **Invariant**: reject `\r`, `\n`, and other control characters in email addresses,
  header parameter names/values, and URIs before they reach a header sink.
- **Automated coverage**: none (scope a presence-check to `Mime\Header`).

### B8. Argument / command injection
- **Anchor**: `grep -rn "escapeshellarg\|proc_open\|\bexec(\|shell_exec\|new Process(" src --include=*.php | grep -v Tests`
- **Invariant**: when passing user-derived values as process arguments, emit an
  end-of-options separator (`--`) before them and reject leading-dash values; on
  Windows, account for the documented quoting hazards.
- **Automated coverage**: none (taint to be precise; advisory at best). `SendmailTransport`
  is the positive exemplar (emits `' --'` before the recipient loop).

### B9. ReDoS / DoS limits
- **Anchor**: `grep -rn "preg_match\|preg_replace\|preg_split\|function.*recurs\|self::\$.*\[" src --include=*.php | grep -v Tests`
- **Invariant**: recursion on attacker input carries a depth/length cap; regexes are
  anchored and backtracking-bounded; process-global caches keyed by input are bounded.
- **Automated coverage**: none (constant-presence at best).
- **Decision boundary**: pure DoS is often outside the CVE pipeline; treat as Low /
  defense-in-depth unless it amplifies another bug.

### B10. XSS / output encoding
- **Anchor**: scope to renderers/dumpers/templates: `grep -rn "->escape(\|htmlspecialchars\|is_safe\|sprintf.*<" src/Symfony/Bridge/Twig src/Symfony/Component/ErrorHandler src/Symfony/Component/VarDumper --include=*.php | grep -v Tests`
- **Invariant**: data placed into HTML output is encoded for its context; "safe" flags
  are only set on genuinely safe output.
- **Automated coverage**: none (taint; scope to `*.html.php`, dumpers).

### B11. HtmlSanitizer URL coverage and denied chars
- **Anchor**: `grep -rn "UrlAttributeSanitizer\|getSupportedAttributes\|DENIED" src/Symfony/Component/HtmlSanitizer --include=*.php | grep -v Tests`
- **Invariant**: every URL-bearing attribute is routed through URL sanitization; URL
  parsing rejects BiDi/format/whitespace chars, including percent-encoded forms.
- **Automated coverage**: none (the attribute set is an answer-key; use a data-provider test).

### B12. SQL injection via key/prefix concatenation
- **Anchor**: `grep -rn "->query(\|->exec(\|\"SELECT\|'SELECT\|\\. \\$" src/Symfony/Component/Cache --include=*.php | grep -v Tests`
- **Invariant**: identifiers (cache prefix, namespace, key) interpolated into SQL must be
  validated/escaped; do not concatenate request-derived strings into a query.
- **Automated coverage**: none (taint).

### B13. CSV formula injection
- **Anchor**: `grep -rn "fputcsv\|class CsvEncoder" src/Symfony/Component/Serializer --include=*.php | grep -v Tests`
- **Invariant**: cell values beginning with `=`, `+`, `-`, `@` (and tab/CR) are escaped so
  a spreadsheet does not execute them.
- **Automated coverage**: none (the trigger-character set is data; cover it with a
  data-provider test).
- **Decision boundary**: the encoder's escaping is opt-in (`csv_escape_formulas`,
  default off) and documented; the default being off is not a finding. The check is that
  the escape path, when enabled, covers the full trigger set.

### B14. Constant-time comparison (beyond webhooks)
- **Anchor**: `grep -rn "UriSigner\|hash_equals\|=== .*hash\|RememberMe.*cookie" src --include=*.php | grep -v Tests`
- **Invariant**: any comparison of a secret-derived value (signed URI, remember-me token,
  CSRF token) uses `hash_equals()`; HMAC inputs have unambiguous field boundaries.
- **Automated coverage**: `HardenedComparisonRule` (inline HMAC compare only).

### B15. Serializer denormalization into an attacker-chosen class
- **Anchor**: `grep -rnE "->denormalize\(|ClassDiscriminator|getMappedObjectType|new \\\$" src/Symfony/Component/Serializer --include=*.php | grep -v Tests`,
  plus any custom (de)normalizer that reads a class or type name from the payload.
- **Invariant**: a (de)normalizer must not instantiate or denormalize into a class name
  taken from an in-band payload field. Resolve polymorphic types through an explicit
  `ClassDiscriminatorMapping` allowlist; never feed an unvalidated `type` discriminator to
  object construction. This is the Serializer analogue of A3.
- **Automated coverage**: none (a value-origin problem; review custom normalizers, or model
  it as Psalm taint into the construction sink).
- **Decision boundary**: a discriminator constrained to a finite mapping is safe; the risk is
  an open `type` field reaching `new`/`denormalize`. Pairs with A3 and the gadget hardening in B3.

### B16. Path traversal / arbitrary file access
- **Anchor**: `grep -rnE "file_get_contents|file_put_contents|fopen|->dumpFile|unlink\(|include |require " src --include=*.php | grep -v Tests`;
  keep the sites whose path is built from input (session ids, cache keys, upload names,
  profiler tokens, command arguments).
- **Invariant**: a filesystem path built from user-derived input must be confined: reject
  `..` and absolute paths, `basename()` untrusted segments, or `realpath()`-check containment
  under a base directory before the open/write/include.
- **Automated coverage**: none (taint; advisory presence-check at best).
- **Decision boundary**: a path assembled only from first-party constants is out of scope;
  CLI tools that run only under local trust are lower priority. The risk is
  request/upload/broker-derived segments.

---

## C. Reviewer checklist (not statically checkable)

Confirm by reading, not by rule. Raise as needs-human-judgement.

### C1. Authorization and secure-default regressions
- Access decision strategies, voter behaviour, `#[IsGranted]`/`#[IsSignatureValid]`
  and HEAD-request handling, default-on CSRF/secure cookies, OIDC claim validation.

### C2. Request / Host trust and cache poisoning
- Absolute-URL generation from the `Host` header, trusted proxy/header handling,
  stripping internal headers before caching, fragment/secret exposure, CAS service URL
  derived from `Host`.

### C3. User-enumeration / timing oracles
- Uniform failure responses and timing for unknown vs known users, including when a
  `UserProvider` caches; consistent status codes on switch-user and login.

### C4. Secret / sensitive-info leak
- Credentials redacted in DSNs, exception messages, and serialized payloads;
  framework/internal names not exposed to clients.

### C5. Weak randomness / token generation
- Security tokens, nonces, and secrets come from a CSPRNG (`random_bytes`/`random_int`),
  not `rand`/`mt_rand`/`uniqid`/`microtime`; such tokens are compared constant-time (B14).
