# Third-party skills

These skills are copies of public projects, installed as project skills for Claude Code. They are instructions
and helper files that shape how Claude works on a page. They are not part of the website and are never uploaded
to the web host (the deploy workflow skips `.claude/`). Each keeps its own license; the license texts are in
`licenses/`.

| Folder | Source | Version copied | License |
|---|---|---|---|
| `frontend-design/` | https://github.com/anthropics/skills (`skills/frontend-design`) | `e4bb5d960d87126ff3b3a8af1a104b7db67560ba` | Apache-2.0 |
| `ui-ux-pro-max/` | https://github.com/nextlevelbuilder/ui-ux-pro-max-skill (`.claude/skills/ui-ux-pro-max`) | `8db36338810d0942567b97ce439affd1619fcf31` | MIT |
| `design-taste-frontend/` | https://github.com/Leonxlnx/taste-skill (`skills/taste-skill`) | `d1a2bb41d927083ac7118f60cfc9cdf711810ea0` | MIT |
| `shadcn/` | https://github.com/shadcn-ui/ui (`skills/shadcn`) | `055aa6b7837b1808456a24beb4c97a677864aa82` | MIT |

## What to know before relying on them

- **`design-taste-frontend` was installed without its Stop hook.** The upstream `SKILL.md` registers a command
  that runs `scripts/gate.mjs` (Node and headless Chrome) every time Claude finishes a turn, and that can hold the
  session open until its page checks pass. Because that is a command that runs on its own and can block a stop, the
  `hooks:` block was removed from the frontmatter. Everything else is unchanged, so the design rules, components and
  scripts are all there. The skill text still mentions the gate; Claude does not run `gate.mjs` or the other
  scripts unless you ask. To turn the hook back on, copy the `hooks:` block from the upstream `SKILL.md` at the
  version above into the frontmatter, and read `scripts/gate.mjs` first.
- **`shadcn` runs one command when it loads:** `npx shadcn@latest info --json`, which downloads and runs the
  official `shadcn` package to read a project's `components.json`. This site has none, so it reports no project.
  The skill is only useful for React and Tailwind projects that use shadcn/ui.
- **`ui-ux-pro-max` runs local Python scripts** (`scripts/search.py`) that search its bundled design data. They
  make no network calls.
- **These skills have opinions** about typography, color, motion and layout, and they do not always agree. When a
  brief names a look, the brief wins.

## Updating

Copy the folder again from the source at a newer version, review what changed, and update the version above.
