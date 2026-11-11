# Linked Packages

This context describes how xAPI packages selected for a Tin Can Launch activity become launchable content and how repository-backed packages are refreshed safely.

## Language

**Package**:
Launchable xAPI content assigned to a Tin Can Launch activity, supplied by a copied archive or a Repository Alias.
_Avoid_: Archive, activity

**Linked Package**:
A Package whose source remains connected through a Moodle Repository Alias and can be refreshed.
_Avoid_: Repository package, package reference

**Repository Alias**:
Moodle's retained reference to repository content rather than a copied upload.
_Avoid_: Shortcut, linked file

**Candidate Revision**:
Newly synchronized package content being evaluated for activation.
_Avoid_: Update, new package

**Active Revision**:
The validated extracted package content currently used for learner launches.
_Avoid_: Current package, live package

**Installation**:
Activation of a package selected through the activity form.
_Avoid_: Refresh, update

**Linked Refresh**:
A check of a Linked Package for a new Candidate Revision.
_Avoid_: Installation, sync

**Activation**:
The switch from the current Active Revision to a validated Candidate Revision.
_Avoid_: Extraction, update

**Rejection**:
An expected Linked Refresh outcome that leaves the Active Revision unchanged.
_Avoid_: Failure, exception

**Candidate Fingerprint**:
A stable identity for a rejected Candidate Revision and its rejection reason.
_Avoid_: Package hash, error hash

## Relationships

- A **Linked Package** is a **Package**
- A **Linked Package** is backed by exactly one **Repository Alias**
- A **Linked Refresh** evaluates at most one **Candidate Revision**
- An **Installation** or **Linked Refresh** may perform an **Activation**
- An **Activation** replaces exactly one **Active Revision**
- A **Rejection** preserves the existing **Active Revision**
- A **Candidate Fingerprint** suppresses repeated notifications for the same **Rejection**

## Example dialogue

> **Dev:** "What happens when a Linked Refresh finds a Candidate Revision with a different xAPI Activity ID?"
> **Domain expert:** "The Candidate Revision is rejected, so learners continue launching the existing Active Revision."

## Flagged ambiguities

- "update" previously meant both **Installation** and **Linked Refresh**; these are distinct use cases.
- "package hash" previously meant both accepted content identity and notification deduplication; **Candidate Fingerprint** includes the rejection reason.
