# Legacy component review

The following historical repositories are intentionally not active monorepo components:

- `delnavazan-enhancements`
- `delnavazan-expansion-hamnavaz`

`delnavazan-enhancements` still documents some Amelia-dependent transitional runtime behaviour. Before retirement, each remaining behaviour must be classified as replaced, still required, or legacy-only and then ported into the correct Platform module or Theme surface where necessary.

Hamnavaz remains a product/module concept in Platform architecture (directory profiles, discovery, public eligibility and profile verification), but the old expansion plugin is not automatically carried forward. Future Hamnavaz work should be implemented against Platform authority rather than copied wholesale from the legacy plugin.
