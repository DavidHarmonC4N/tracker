# WCAG 2.2 New Success Criteria Reference

WCAG 2.2 adds nine success criteria and removes 4.1.1 Parsing (treat it as always satisfied). This file replaces the
upstream version, which mislabelled 2.4.11 and left out four criteria. Levels are as published in WCAG 2.2.

| SC | Name | Level |
|---|---|---|
| 2.4.11 | Focus Not Obscured (Minimum) | AA |
| 2.4.12 | Focus Not Obscured (Enhanced) | AAA |
| 2.4.13 | Focus Appearance | AAA |
| 2.5.7 | Dragging Movements | AA |
| 2.5.8 | Target Size (Minimum) | AA |
| 3.2.6 | Consistent Help | A |
| 3.3.7 | Redundant Entry | A |
| 3.3.8 | Accessible Authentication (Minimum) | AA |
| 3.3.9 | Accessible Authentication (Enhanced) | AAA |

A focus indicator that is missing or invisible fails 2.4.7 Focus Visible (AA), not a new criterion.

## 2.4.11 Focus Not Obscured (Minimum), Level AA

When a component receives keyboard focus, it is not entirely hidden by author-created content such as a sticky header,
footer, cookie banner or chat widget. Partly hidden is allowed at AA. 2.4.12 (AAA) requires that no part is hidden.

**Check:** tab through the page with a sticky header at a narrow width and at 200% zoom.

```css
/* Keep focused elements clear of a sticky bar */
html { scroll-padding-top: 5rem; }
:focus { scroll-margin-top: 5rem; }
```

## 2.4.13 Focus Appearance, Level AAA

The focus indicator is at least as large as a 2 CSS px thick perimeter of the unfocused component, and has at least
3:1 contrast between its focused and unfocused states. Aim for this even at AA, since 2.4.7 only asks for a visible
indicator.

```css
:focus-visible {
  outline: 2px solid #005fcc;
  outline-offset: 2px;
}
```

## 2.5.7 Dragging Movements, Level AA

Anything that works by dragging also works with a single pointer without dragging (click, tap), unless dragging is
essential.

## 2.5.8 Target Size (Minimum), Level AA

Pointer targets are at least 24 by 24 CSS pixels, except when:

- **Spacing:** an undersized target has enough space around it that a 24 px circle centered on it touches no other
  target or circle.
- **Equivalent:** another control on the same page does the same thing and meets the size.
- **Inline:** the target is a link inside a sentence or block of text.
- **User agent:** the size is set by the browser and not changed by the author.
- **Essential:** a particular presentation is required.

44 by 44 px is the AAA criterion 2.5.5 and a good touch target, but it is not required at AA.

```css
button, [role="button"], input, select, summary { min-height: 24px; min-width: 24px; }
@media (pointer: coarse) { button, input, select, summary { min-height: 44px; } }
```

## 3.2.6 Consistent Help, Level A

If a page has a way to get help (human contact details, a contact mechanism, a self-help option, or an automated
contact mechanism) and it repeats across pages, it appears in the same relative order each time.

## 3.3.7 Redundant Entry, Level A

Information the user already entered in the same process is filled in automatically or offered to select, unless
re-entry is essential, needed for security, or the information is no longer valid.

## 3.3.8 Accessible Authentication (Minimum), Level AA

Signing in does not depend on a cognitive function test (remembering a password, solving a puzzle, transcribing
characters) unless another method is offered, or a mechanism helps, such as allowing paste and password managers.
Recognizing objects or the user's own uploaded content is allowed at AA. 3.3.9 (AAA) removes those two exceptions.
