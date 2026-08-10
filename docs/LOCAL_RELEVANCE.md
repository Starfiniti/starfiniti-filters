# Local relevance contract

Last reviewed: 2026-08-09

This document fixes the behavior of local index schema 3, analyzer revision 1, and ranking profile `local-default-v1`. Changes to tokenization, position encoding, candidate generation, field weights, boosts, or penalties require a new analyzer revision or ranking profile and a shadow rebuild before activation.

## Analysis and storage

- Text is Unicode-normalized, case-folded, accent-folded, and tokenized deterministically.
- Ordered token sequences retain repeated terms. Each posting stores term frequency, field length, and a versioned delta-varint position blob.
- Position blobs are bounded to 1,024 positions and 2,048 bytes. Malformed or oversized data fails closed.
- Documents retain normalized title and searchable text for exact-title and phrase verification.
- The term vocabulary has a trigram index used only to produce bounded typo candidates.

## Matching and ranking

- Exact SKU and identifier matches remain deterministic and receive the strongest boost.
- Exact normalized titles, quoted phrases, field weights, and fuzzy penalties are centralized in `RankingProfile`; providers must not introduce hidden boosts.
- A quoted phrase is a mandatory constraint. Phrase matching is verified against normalized document text rather than inferred only from unordered postings.
- Typo recovery uses Unicode optimal-string-alignment Damerau-Levenshtein distance. Three-to-five-character tokens allow one edit; longer tokens allow two. Tokens containing digits are never expanded.
- At most 32 trigram candidates are inspected per query token and at most 16 fuzzy expansions are admitted for the whole request. Fuzzy matches receive a `0.55` score factor and emit `fuzzy_expansions_applied:N`.
- Candidate and response bounds from the public search contract remain in force. No-result adversarial input has an integration latency guard.

## Governed query policy

- Synonyms and stop words live inside the immutable desired-configuration revision. They are hot-applied at query time and do not require an index rebuild.
- Equivalent and directional synonym rules are scoped by locale and channel and may have RFC 3339 effective start/end times. Identifiers are unique, directional graphs must be acyclic within a scope, each rule has at most eight terms, there are at most 256 rules, and a query admits at most 16 expansions.
- Synonym matches receive a `0.75` score factor and emit `synonym_expansions_applied:N`. Explain mode identifies the rule and expansion type.
- Stop words are keyed by locale and bounded to 512 entries per locale. Removed terms are explained and declared with `stop_words_removed:N`.
- A non-empty query that becomes empty after stop-word removal returns no results with `all_stop_words_safe_no_result`. Untokenizable non-empty input likewise returns `unsearchable_query_safe_no_result`; neither path can become an unbounded match-all query.
- Exact-query curations are scoped by locale/channel, support effective dates and priorities from -1000 to 1000, and have unique stable identifiers. Rules with the same query, scope, and priority are rejected as ambiguous.
- Each rule may pin, boost, bury, or hide at most 50 positive product IDs and may provide one safe internal-path redirect. A product can have only one action within a rule. Across matching rules, higher priority wins and the stable rule ID breaks ties.
- Pins are resolved at the candidate stage: they may introduce an otherwise nonmatching public product, but never bypass canonical visibility, password, locale, channel, customer-scope, or active storefront filters. Added pins participate exactly once in totals, facets, and pagination.
- Hides are applied before exact-SKU inclusion and candidate counting. Boosts and buries adjust the final bounded candidate score. Explanations identify the rule, priority, action, text-only score, and adjusted score.
- Redirects must start with one `/`, cannot be protocol-relative, contain an origin, credentials, backslashes, spaces, or control characters, and are capped at 2,048 bytes. The discovery client independently rechecks same-origin resolution before navigation. Autocomplete never redirects while a user types.
- A rule may rewrite to one bounded replacement query and may add one canonical filter AST. Rewrite graphs must be acyclic and only one rewrite may exist for a query/scope. Added filters use the same 32-node/four-level allowlisted compiler as storefront filters and are combined with—not substituted for—caller filters. Unsupported action keys and provider-native filter fields fail validation.

Administrators can edit synonym, stop-word, and curation JSON with a mandatory reason. Saving uses the same immutable plan/approval/execution engine as other configuration changes. The administrator laboratory explains the active revision. `POST /wp-json/starfiniti-search/v1/control/relevance/preview` validates and runs a draft ranking policy without persisting it or recording search analytics; it requires the dedicated configuration capability and returns `mutation_performed: false` plus a deterministic preview checksum.

## Output safety and diagnostics

- Highlights are arrays of `{text, highlighted}` segments. They contain no HTML and callers must render `text` as text, never as markup.
- Results may expose `matched_fields` to public callers; they do not expose stored product documents or ranking internals.
- Relevance explanations are returned only for an authenticated administrator who explicitly requests administrator test mode and `include_explanation`. Explanations include normalized input, phrase and fuzzy decisions, matched fields, raw score, filter presence, and ranking-profile identity.
- Anonymous callers cannot enable administrator explanations by setting request options.

## Qualification evidence

Unit tests cover ordered/repeated tokens, bounded edit distance, position-codec round trips and corruption handling, scoped policy analysis, cycle/conflict/redirect rejection, deterministic curation priority, and safe empty-query classification. Real-MariaDB tests cover immutable policy activation/restore, weighted directional expansion, stop-word behavior, candidate-stage pin/boost/bury/hide, exact-SKU filter enforcement, redirect safety, curated totals/facets/pagination, active-filter and restricted-product pin denial, channel/effective visibility isolation, administrator rendering, draft-preview authorization/non-mutation, schema identity, phrases, safe highlights, typo recovery, numeric-SKU non-expansion, persisted positions/trigrams, and rollback. The disposable 100,000-document benchmark covers the bounded common-term query plan; it is not a full WooCommerce ingestion certification.
