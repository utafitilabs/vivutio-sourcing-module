# vivutio/sourcing-module

**Sourcing for vivutio.** Rooms requested from the camps and lodges an
organization trades with: sent through the core's partner channel, the camp's
reply recorded, every step kept on the request.

## Contents

- [Install](#install)
- [How it works](#how-it-works)
- [Who may do what](#who-may-do-what)
- [Development](#development)
- [Licence](#licence)

## Install

In a vivutio installation:

```bash
composer require vivutio/sourcing-module
php bin/console doctrine:migrations:migrate
```

Its recipe registers the bundle and mounts its pages with
`config/routes/sourcing.yaml`, a file the installation owns.

## How it works

A **request** asks an accommodation partner (a partner kept in the core as
accommodation, traded with now) to hold rooms: for whom, arriving when, for
how many nights, and up to four lines of rooms ("2 × Double"). It is referred
to as "RQ-0001".

It is **sent as it is made**, through the core's `PartnerChannelInterface`: by
the manual channel, an email to the partner's address with the reference in
the subject. The camp's **reply** is recorded by hand: confirmed, with the
camp's own reference, or declined, with a note. A request waiting or confirmed
is **cancelled** with a message to the camp saying so. Each step is kept on the
request, with who took it and when.

The module names no other module; it reads partners through the core's
`PartnerDirectoryInterface` and keeps only a partner's id.

## Who may do what

| Pair | Who |
|---|---|
| `room_requests.read` | The register and a request's page |
| `room_requests.record` | Sending a new request |
| `room_requests.manage` | Recording replies and cancelling |

Each is a module pair, held where the person's department allows it too. The
suite extends the core's authority test base; `tests/authority-table.md` is
the reviewed table.

## Development

```bash
composer update
composer check
```

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
