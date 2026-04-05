# Origin Linker — Design Spec

**Date:** 2026-04-04  
**Status:** Approved

---

## Overview

`origin-linker` is a framework-agnostic PHP Composer library for bidirectional HTTP communication between a parent project and multiple child projects. It handles authentication, typed payloads per service category, and network-level retry logic.

---

## Architecture

### Package Structure

```
src/
├── Linker.php              # Sends requests to parent or child projects
├── Receiver.php            # Validates incoming requests, returns standard response
├── Config.php              # Holds project configuration (keys, urls, retry)
├── HttpClient.php          # Guzzle wrapper with retry logic
├── Response.php            # Result of a request: status, code, body, error
├── Payload/
│   ├── BasePayload.php     # Base: category, source, timestamp, uuid
│   ├── CleaningPayload.php # area, bedrooms, bathrooms, rooms, deep_clean
│   └── TransferPayload.php # pickup, dropoff, waypoints, trip_type, passengers, flight_no
└── Exceptions/
    ├── AuthException.php
    └── LinkerException.php
```

---

## Components

### `Linker`

Central class. Instantiated with `Config` and an optional `HttpClient`.

```php
$linker->sendToParent(BasePayload $payload): Response
$linker->sendToChild(string $slug, BasePayload $payload): Response
```

- `sendToChild()` is only available when the config has a `children` section (parent project).
- `sendToParent()` is only available when the config has a `parent` section (child project).

### `Receiver`

Validates incoming requests. Used in the endpoint handler of any project.

```php
$receiver->handle(array $headers, string $body): Response
```

- Checks `X-Linker-Key` against allowed keys in config.
- On the parent project: also verifies the sender domain from `X-Linker-Source` against the allowed domains in `children` config.
- On child projects: verifies API key only (parent is trusted).
- Returns `Response` with parsed `BasePayload` subclass on success.

### `Config`

```php
Config::fromArray(array $config): Config
Config::fromFile(string $path): Config
```

### `HttpClient`

Wraps Guzzle. Implements retry only on network-level exceptions (connection refused, timeout). Does NOT retry on HTTP 4xx or 5xx — those are returned immediately as errors.

### `Response`

```php
$response->isOk(): bool
$response->statusCode(): int
$response->payload(): BasePayload|null
$response->error(): string|null
$response->toArray(): array   // {"status":"ok|error","message":"...","data":{}}
```

---

## Authentication

Every outgoing request includes:
```
X-Linker-Key: <api_key of sender>
X-Linker-Source: <domain of sender>
```

**Parent project `Receiver`:**
1. Checks `X-Linker-Key` matches one of the keys in `children[*].api_key`
2. Checks `X-Linker-Source` matches the `domain` of the corresponding child

**Child project `Receiver`:**
1. Checks `X-Linker-Key` matches `parent.api_key`

---

## Configuration

### Parent project config

```php
return [
    'api_key'  => 'parent-secret-key',
    'children' => [
        'cleaning' => [
            'url'     => 'https://cleaning.example.com/linker',
            'api_key' => 'child-cleaning-key',
            'domain'  => 'cleaning.example.com',
        ],
        'transfer' => [
            'url'     => 'https://transfer.example.com/linker',
            'api_key' => 'child-transfer-key',
            'domain'  => 'transfer.example.com',
        ],
    ],
    'retry' => ['attempts' => 3, 'delay_ms' => 500],
];
```

### Child project config

```php
return [
    'api_key' => 'child-cleaning-key',
    'parent'  => [
        'url'     => 'https://main.example.com/linker',
        'api_key' => 'parent-secret-key',
    ],
    'retry' => ['attempts' => 3, 'delay_ms' => 500],
];
```

Child projects have no `children` section — they physically cannot address other child projects.

---

## Data Flow

### Child receives order → forwards to parent

```
[Child Project]
  → receives order from customer
  → saves to own database
  → Linker::sendToParent(new CleaningPayload([...]))
  → [Parent] Receiver::handle() → validates → saves → returns 200 {status: ok}
```

### Parent forwards order to another child

```
[Parent Project]
  → Linker::sendToChild('transfer', new TransferPayload([...]))
  → [Child Transfer] Receiver::handle() → validates → saves → returns 200 {status: ok}
```

### Parent receives order directly → forwards to child

```
[Parent Project]
  → receives order from customer
  → Linker::sendToChild('cleaning', new CleaningPayload([...]))
  → [Child Cleaning] Receiver::handle() → validates → saves → returns 200 {status: ok}
```

---

## Payloads

### BasePayload fields (all payloads include these)

| Field | Type | Description |
|---|---|---|
| `category` | string | Service category (e.g. `cleaning`, `transfer`) |
| `source` | string | Sender identifier |
| `timestamp` | int | Unix timestamp |
| `uuid` | string | Unique request ID |
| `data` | array | Category-specific fields |

### CleaningPayload fields

| Field | Type | Required |
|---|---|---|
| `area` | int (m²) | yes |
| `bedrooms` | int | yes |
| `bathrooms` | int | yes |
| `rooms` | string[] | yes |
| `deep_clean` | bool | yes |

### TransferPayload fields

| Field | Type | Required |
|---|---|---|
| `pickup` | string | yes |
| `dropoff` | string | yes |
| `waypoints` | string[] | no |
| `trip_type` | enum: `to_airport`, `from_airport`, `point_to_point`, `hourly` | yes |
| `passengers` | int | no |
| `luggage` | int | no |
| `flight_no` | string | no |

---

## Error Handling

| Scenario | Behavior |
|---|---|
| Network error (timeout, connection refused) | Retry up to `attempts` times with `delay_ms` pause, then return `Response(status=error)` |
| HTTP 4xx | Immediate `Response(status=error, code=4xx)`, no retry |
| HTTP 5xx | Immediate `Response(status=error, code=5xx)`, no retry |
| Invalid API key | `Receiver` returns `401 + {status: error, message: Unauthorized}` |
| Domain not allowed | `Receiver` returns `403 + {status: error, message: Forbidden}` |
| Invalid payload fields | `LinkerException` thrown before request is sent |

---

## Usage Examples

### Sending from child to parent

```php
$linker = new Linker(Config::fromArray($config));

$response = $linker->sendToParent(new CleaningPayload([
    'area'       => 120,
    'bedrooms'   => 3,
    'bathrooms'  => 2,
    'rooms'      => ['kitchen', 'living_room'],
    'deep_clean' => true,
]));

if ($response->isOk()) {
    // success
} else {
    error_log($response->error());
}
```

### Sending from parent to child

```php
$response = $linker->sendToChild('transfer', new TransferPayload([
    'pickup'     => 'Kyiv, Khreshchatyk 1',
    'dropoff'    => 'Boryspil Airport',
    'trip_type'  => 'to_airport',
    'passengers' => 2,
    'flight_no'  => 'PS101',
]));
```

### Receiving a request (any framework)

```php
$receiver = new Receiver(Config::fromArray($config));
$result = $receiver->handle(getallheaders(), file_get_contents('php://input'));

if ($result->isOk()) {
    $payload = $result->payload();
    // save to database...
}

http_response_code($result->statusCode());
echo json_encode($result->toArray());
```

---

## Testing

- **Unit tests:** `PayloadTest`, `ReceiverTest`, `ConfigTest`, `ResponseTest`
- **MockHttpClient:** swap real HTTP for test responses

```php
$linker = new Linker($config, new MockHttpClient(['status' => 'ok', 'message' => 'received']));
$response = $linker->sendToParent(new CleaningPayload([...]));
```

---

## Composer Package

```json
{
    "name": "origin/linker",
    "description": "Framework-agnostic PHP library for connecting parent and child projects via HTTP",
    "type": "library",
    "require": {
        "php": ">=8.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "autoload": {
        "psr-4": {
            "Origin\\Linker\\": "src/"
        }
    }
}
```

**Namespace:** `Origin\Linker`  
**Min PHP version:** 8.0
