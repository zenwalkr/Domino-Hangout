# Domino Hangout

Domino Hangout is a self-contained, browser-based multiplayer domino game written in PHP. It provides a shared lobby, real-time turn polling, several domino rule engines, CPU opponents, text chat, and optional peer-to-peer voice/video chat.

The application is intentionally packaged as a single PHP file. There is no build step, JavaScript package manager, framework, or separate backend service.

## Features

- Multiplayer tables for 2–4 players.
- Public table browser with filtering, sorting, and an “open tables only” view.
- Private tables that can be joined only with their six-character code.
- Five rule modes:
  - **All Fives (Muggins)** — score when the exposed board ends total a multiple of five.
  - **All Threes** — score when the exposed board ends total a multiple of three.
  - **Fives & Threes (UK)** — score for each applicable multiple of five and three.
  - **Block Dominoes** — no drawing; pass when no legal move exists.
  - **Draw Dominoes** — draw from the boneyard when no legal move exists.
- Configurable match targets: 31, 61, 100, 150, or 250 points.
- Automatic round progression and match winner handling.
- Host controls for starting and resetting a table.
- CPU bots for solo testing or filling an otherwise empty table.
- Persistent table state, chat, and WebRTC signaling in SQLite.
- Browser-to-browser voice and video chat using WebRTC.
- Responsive mobile-friendly board with pan, zoom, hand scaling, board tile scaling, and player-card sizing.
- Twelve domino themes and eight board colors.
- Optional highlighting and automatic panning to the last played tile.
- Administrator settings for the game name, defaults, room list height, inactivity cleanup, password changes, and table management.

## Requirements

The server must provide:

- PHP 8.0 or newer.
- The PHP SQLite3 extension.
- A web server that can execute PHP files, such as Apache, nginx with PHP-FPM, or PHP’s built-in development server.
- Write permission for the directory containing `index.php`, because the app creates and updates `domino.sqlite`.
- HTTPS in production if voice/video chat is required. Browsers generally restrict camera and microphone access to secure contexts.

The browser should be a current version of Chrome, Edge, Firefox, or Safari. WebRTC support and camera/microphone permissions are required only for voice/video features; the board and text chat work without them.

## Quick start

1. Clone or copy this repository into a PHP-enabled web directory.

   ```bash
   git clone https://github.com/zenwalkr/Domino-Hangout.git
   cd Domino-Hangout
   ```

2. Confirm the SQLite3 extension is available:

   ```bash
   php -m | grep -i sqlite
   ```

   You should see `sqlite3`. On Debian or Ubuntu, the package is commonly named `php-sqlite3`.

3. Start the local development server:

   ```bash
   php -S 127.0.0.1:8080
   ```

4. Open [http://127.0.0.1:8080](http://127.0.0.1:8080) in a browser.

5. Create a table, choose a nickname, rule mode, target score, and optional private-table setting. Share the generated code with other players, or use **Add CPU Bot** to test the game locally.

On first request, the application creates `domino.sqlite` and its tables automatically. No database migration command is required.

## Production deployment

Copy `index.php` to the public directory of the PHP site. Do not expose the SQLite database through a separate static-download location. If the database is stored in the document root, configure the web server to deny direct access to:

- `domino.sqlite`
- `domino.sqlite-wal`
- `domino.sqlite-shm`

The application uses one SQLite file for tables, game state, chat messages, WebRTC signaling messages, and administrator settings. The containing directory must be writable by the PHP process.

For a public deployment:

1. Serve the site over HTTPS.
2. Use a strong administrator password and change it immediately from the Admin panel.
3. Restrict filesystem permissions so only the web-server account can write the database.
4. Back up `domino.sqlite` while the application is stopped or use a SQLite-aware backup procedure.
5. Consider rate limiting and authentication at the web-server or reverse-proxy layer if the app will be exposed to the public Internet.
6. Verify that camera and microphone permissions are allowed for the site when using WebRTC.

## How to play

### Create or join a table

From the home screen:

- Enter a nickname and create a table.
- Select the rule mode and target score before creating it.
- Enable **Make Private** to remove the table from the public list. Players can still join with the code.
- Select a listed public table, enter its nickname, and join.
- Use **Join by Code** for private tables or direct invitations.

The creator is the host. A table needs at least two seated players before the host can start the match, and supports up to four players.

### Lobby

In the lobby, players can see the table code, rule mode, target score, and seated players. The host can:

- Add CPU bots.
- Start the match once at least two players are present.
- Reset the table after a match.

A player can leave from the lobby or game screen. If all human players leave, the match is marked finished.

### Playing a turn

The current player selects a domino in their hand and then selects a legal board end. When only one board end is possible, auto-play can place the tile automatically if the setting is enabled.

Depending on the rule mode:

- **Draw** draws from the boneyard when no playable tile exists.
- **Pass** is available when no legal move remains; Block mode uses pass immediately, while draw-based modes pass after the boneyard is empty.
- The game validates turns, tile ownership, legal endpoints, and scoring on the server.

The board can be dragged to pan. Use the mouse wheel or a touch pinch gesture to zoom.

### Scoring

All Fives and All Threes score the exposed endpoint total when it is divisible by the mode’s number. Fives & Threes awards the applicable quotient for each divisor. Block and Draw modes do not award move points, but the match still tracks the selected target score and round results.

The first player to reach the configured target wins the match. When a round ends, players see a summary and the next round advances automatically after the popup is dismissed.

### Chat and video

- Open the table chat from the chat button in the game controls.
- Enable microphone or camera access with the voice/video buttons.
- Double-click or double-tap a player card to open the video view.
- WebRTC media is peer-to-peer. The PHP server is used only to exchange signaling data; it is not a media relay.
- If media cannot connect across networks, a TURN server may be needed. The current app uses the browser’s default WebRTC ICE behavior and does not configure a custom TURN service.

## Settings

Each player can open the settings panel during a match and change:

- Hand size.
- Board tile size.
- Expanded player-card height.
- Auto-play when only one move is available.
- Last-played tile highlighting.
- Automatic panning to the last-played tile.
- Domino theme.
- Board color.

These preferences are stored in the player’s browser using `localStorage`. They are not stored in the server database.

## Administrator controls

Open **Admin** from the home screen and authenticate with the administrator password.

The current source contains a legacy first-use fallback password of `adminpass5`. Change it immediately after deployment. Once changed, the password is stored as a PHP password hash in SQLite; the plaintext password is not stored.

Administrator settings include:

- Application/game name.
- Default hand scale.
- Default board tile scale.
- Default expanded player-card height.
- Default auto-play behavior.
- Default last-tile highlighting.
- Default last-tile auto-pan.
- Inactivity timeout for lobby and playing tables; `0` disables cleanup.
- Public room-list height.
- Password change.
- Reset all active tables back to their lobbies.
- Delete all tables, including their chat and signaling records.

Admin actions are protected by the password but are not a substitute for HTTPS or web-server access control.

## Application internals

The app exposes its browser UI and its lightweight HTTP API from the same `index.php` endpoint.

The browser uses:

- GET requests to retrieve the current public state and table list.
- POST requests for table creation, joining, gameplay, chat, admin operations, and WebRTC signaling.
- Session storage for the player token and current table code.
- One-second polling for game updates.
- SQLite transactions and short file locks to coordinate concurrent table actions.

The database schema is created or upgraded at runtime:

- `games` stores table metadata and serialized game state.
- `chat` stores the latest table chat history.
- `signals` queues WebRTC signaling payloads between players.
- `admin_settings` stores application defaults and the hashed admin password.

## Troubleshooting

### “Class SQLite3 not found”

Enable the PHP SQLite3 extension and restart PHP-FPM or the web server. Check the loaded CLI modules with `php -m`; the web SAPI may use a different PHP installation, so verify that one too.

### Database cannot be opened or updated

Check that the PHP process can write to the directory containing `index.php`. Also check ownership and permissions for existing `domino.sqlite`, `domino.sqlite-wal`, and `domino.sqlite-shm` files.

### Players cannot see each other’s changes

Make sure all players are using the same deployed URL and that requests to the PHP endpoint are not cached by a proxy. The application sends no-cache headers and polls approximately once per second.

### Voice or video does not work

Use HTTPS, allow the browser’s camera/microphone prompts, and check browser permissions for the site. If peers are on networks that block direct connections, configure a TURN service in the WebRTC connection setup.

### A private table is missing

Private tables intentionally do not appear in the public table list. Use the six-character table code to join.

## Project layout

```text
.
├── index.php       # Complete PHP application, UI, API, game engine, and client JavaScript
├── .gitignore      # Keeps local SQLite runtime files out of version control
└── domino.sqlite   # Created at runtime; intentionally not committed
```

## Development notes

Run a syntax check before deploying changes:

```bash
php -l index.php
```

For a clean local test database, stop the development server and remove the local `domino.sqlite` plus any `-wal` or `-shm` files, then load the app again. This deletes local tables, chat, and settings, so back up the database first if it matters.

The project currently has no automated test suite or dependency lockfile. Manual testing should cover table creation, joining, host start/reset, every rule mode, CPU turns, chat, private-table access, admin settings, database permissions, and WebRTC permission flows.

## License

No license has been specified yet. Until a license is added, all rights remain with the copyright holder. Add an explicit license before distributing or reusing the project.
