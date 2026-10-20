# Software ownership and release history

This file explains how the provenance of CampaignsHub can be shown from the repository alone.

## The record
- **Git history is the release ledger.** `main` is protected: every change reaches it as a pull request that
  passed the mandatory checks (backend, frontend, image build, and the three browser gates), is squash-merged,
  and carries the pull request number in its subject, e.g. `(#774)`. `git log --first-parent main` therefore reads
  as the ordered list of releases, each with its description and its verification.
- **Authorship is recorded per commit**, including the `Co-Authored-By` trailer on work produced with Claude
  Code, so who wrote what, and with which assistant, is in the history rather than in anyone's memory.
- **Every shipped feature has a row** in `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` naming its code, its tests,
  its status (code, merge, deploy, Production acceptance recorded separately) and its evidence, and every
  Owner-reported defect has an entry in `docs/OWNER_OBSERVED_DEFECTS.md`. `docs/RESUME_STATE.md` is the dated
  narrative of what was decided and why.
- **Deployments** are workflow runs of `deploy-production.yml`, each tied to the commit it shipped; the run list
  is the deployment history.

## Producing an ownership bundle
```bash
git log --first-parent main --date=iso --format='%H %ad %an %s' > release-ledger.txt
git shortlog -sne --all > contributors.txt
git archive --format=tar.gz -o campaignshub-$(git rev-parse --short HEAD).tar.gz HEAD   # source at a release, no history, no .env
```
Annotate a release by tagging the merge commit (`git tag -a v2026.10.20 -m '…' <sha>` then push the tag); a signed
tag (`-s`) adds a cryptographic statement of who tagged it.

## What this is not
It is not a licence and not legal advice. The repository carries no licence file, which means the code is
proprietary by default; a copyright notice and licence terms are the Owner's decision with counsel, and the
products' own legal pages are separate documents.
