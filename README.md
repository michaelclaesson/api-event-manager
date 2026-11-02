<!-- SHIELDS -->
[![Contributors][contributors-shield]][contributors-url]
[![Forks][forks-shield]][forks-url]
[![Stargazers][stars-shield]][stars-url]
[![Issues][issues-shield]][issues-url]
[![License][license-shield]][license-url]
![PHP Version](https://img.shields.io/badge/PHP->=8.1-blue)
![Unit Tests](https://github.com/helsingborg-stad/api-event-manager/actions/workflows/php-test.yaml/badge.svg)


# API Event Manager

  A WordPress plugin that creates a api that may be used to manage events.
  
  [Report Bug](https://github.com/helsingborg-stad/api-event-manager/issues)
  ·
  [Request Feature](https://github.com/helsingborg-stad/api-event-manager/issues)

## About API Event Manager

### Built With

* PHP
* WordPress

## Getting Started

To get a local copy up and running follow these simple steps.

### Development environment (VS Code Devcontainer)

#### Prerequisites

* Docker
* VS Code

#### Installation of development environment.

1. Install VS Code.
1. Install the extension [Remote Development](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.1scode-remote-extensionpack).
1. Clone this repo.
1. Open the folder in VS Code.
1. Open the command palette (cmd + shift + p).
1. Type `Remote-Containers: Reopen in Container` and select this alternative.
1. Wait for the container to build.
1. Run composer install in the VS Code integrated terminal.
1. Run the provided VS Code Task `install acf pro`. This will download and install ACF Pro, which is required for this WordPress plugin to work.

## Tests

### Run tests
Run `composer test` in the terminal.

## Constants

## Contributing

Contributions are what make the open source community such an amazing place to be learn, inspire, and create. Any contributions you make are **greatly appreciated**.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

Distributed under the [MIT License][license-url].

## Acknowledgements

- [othneildrew Best README Template](https://github.com/othneildrew/Best-README-Template)


<!-- MARKDOWN LINKS & IMAGES -->
<!-- https://www.markdownguide.org/basic-syntax/#reference-style-links -->
[contributors-shield]: https://img.shields.io/github/contributors/helsingborg-stad/api-event-manager
[contributors-url]: https://github.com/helsingborg-stad/api-event-manager/graphs/contributors
[forks-shield]: https://img.shields.io/github/forks/helsingborg-stad/api-event-manager.svg?style=flat-square
[forks-url]: https://github.com/helsingborg-stad/api-event-manager/network/members
[stars-shield]: https://img.shields.io/github/stars/helsingborg-stad/api-event-manager.svg?style=flat-square
[stars-url]: https://github.com/helsingborg-stad/api-event-manager/stargazers
[issues-shield]: https://img.shields.io/github/issues/helsingborg-stad/api-event-manager.svg?style=flat-square
[issues-url]: https://github.com/helsingborg-stad/api-event-manager/issues
[license-shield]: https://img.shields.io/github/license/helsingborg-stad/api-event-manager.svg?style=flat-square
[license-url]: https://github.com/helsingborg-stad/api-event-manager/blob/main/LICENSE


## Reusable places

Offline events can choose a shared `event_place` term or submit a new place for review. Each term stores an ACF map in `locationAddress`. The event keeps its own optional `locationDetails`, used for rooms and directions inside the venue. Existing events without a term retain their original location until an editor explicitly links them.

New proposals stay on the pending event. Publishing as an editor with `manage_categories` creates and links the place; publishing repeatedly does not create duplicates. This also supports approval through `wp_update_post`, including Eslöv Event Review's quick approval. A proposal submitted by an account without place-management permission cannot create a publicly reusable place by publishing itself.

Manage places under Events → Places. Changing the name or map updates every linked event's compatibility fields, including past events. The slug remains stable on a name-only edit. A place in use cannot be deleted. The existing `locationName` and `locationAddress` fields remain available for integrations; `locationDetails` must not be appended to the canonical name. New places get the slug `name-eventId-addresshash`; change it with the `EventManager/PlaceSlug` filter (`$slug, $name, $address, $eventId`), keeping it unique per name and address. Name-based External Content filters need updating when a place is renamed. Typesense and External Content receive edits on their normal subsequent sync; no new external indexing connection is introduced.

- `GET /wp-json/wp/v2/event-places` lists registered terms (WordPress pagination/search applies). Each term includes a Schema.org `place` object with name, address, latitude and longitude.
- `GET /wp-json/event-manager/v1/places/matches?name=...` returns normalized-name matches with addresses. Matching ignores case and surrounding/repeated whitespace, normalizes Unicode, and preserves Swedish letters. A warning never merges places automatically.
- Events continue to expose their ACF fields and term IDs. Municipio schema output uses `location` / `Place` with event-specific `description`. The headless schema transformer must map `acf.locationDetails` to `location.description` as well.

The selector's frontend behavior requires the companion Frontend Form support for `frontend_form_linked_location`. Without that enhancement, the ordinary ACF fields and editor controls still work. Register management uses `manage_categories`; organizers may assign places through the event submission flow without permission to edit shared data.

### Local integration check

With the two feature branches active on a `.local` WordPress site, an event form with ID 12 selected for Eslöv Event Review, and an editor account:

```sh
wp --url=https://event.eslov.local --user=<editor> eval-file wp-content/plugins/api-event-manager/tests/integration/places.php
```

The check disables email and outbound HTTP for that process, creates isolated fixtures and removes them afterwards. Unit tests run with the existing `composer test` command. Older dependencies emit PHP 8.4 deprecations; use a compatible PHP runtime or suppress dependency deprecations in a local PHPUnit configuration.
