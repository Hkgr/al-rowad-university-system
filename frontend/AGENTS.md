# Frontend visual consistency

- Do not invent an independent visual style for a feature unless the user explicitly requests it.
- Before changing a UI, inspect named, current reference pages and their actual rendered components. Record those paths in the feature's documentation.
- Reuse the project's headers, tables, filters, actions, dialogs, notices and pagination. Match existing Cairo sizes/weights, spacing, control heights, borders, radii, shadows and icons; green and Cairo alone do not establish visual consistency.
- Keep necessary feature-specific behavioral wrappers visually consistent with the selected references. Remove redundant copies when a shared component already supports the behavior.
- Do not change shared components or global design in ways that alter unrelated pages just to fit one feature.
- Compare rendered desktop/mobile screenshots against the references. Distinguish synthetic fixtures from live backend verification; CSS source inspection alone is not visual acceptance.
