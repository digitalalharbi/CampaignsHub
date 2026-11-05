# Adding a provider

A provider is three things: a catalogue entry, a connector, and a protocol config entry. The engine (discovery, binding, sync, run ledger, metrics) is not edited. `backend/tests/Feature/NewProviderNeedsNoEngineEditTest.php` proves that with `backend/tests/Support/Providers/FakeAdsConnector.php`, which is the worked example below: it exists only under `tests/`, and nothing under `app/` names it.

This covers an **ad platform**, which is the path the proof exercises. See [Other families](#other-families) for what is different.

## 1. Declare it

Add a private method to `ProviderCatalogue` and list it in `build()`. `FakeAdsConnector::definition()` has the shape. The part that matters is `capabilities`:

```php
capabilities: new ProviderCapabilities(
    resourceType: ResourceType::AdAccount,
    accountType: 'ad_account',
),
```

Everything else defaults to "no". **Declare what the code does, not what the provider's API could do.** The rest of the declaration lives on the definition itself: `supportsRefresh`, `webhooks`, `webhookSignatureHeader`, `auth`. If a capability needs more than a flag, see the [runbook table](../PRODUCTION_RUNBOOK.md#10-provider-capability-declarations).

## 2. Write the connector

Extend `ApiAdvertisingConnector` and implement `platform()`, `fetchAdAccounts()`, `fetchCampaigns()`, `fetchAdSets()`, `fetchAds()` and `fetchInsights()`. A level the provider does not have returns `[]`.

- Reach the network through `PlatformHttp::client($key)` and read answers through `$this->read()`, so the retry policy and the receipts come with it.
- Report only what the provider reported. A measure it did not send is absent from the row, never zero.
- `fetchAdAccounts()` returns the rows `AccountDiscovery` stores: `external_id`, `name`, `currency`, `timezone`, `status`, `parent_external_id`, `raw`.

Add the class to the list in `AdvertisingConnectorRegistry`'s constructor.

Optional abilities are interfaces, and declaring one means implementing it:

| Declare | Implement |
|---|---|
| `entityGrains` | `ReportsEntityGrains` |
| `creativeInsights` | `ReportsCreativeInsights` |
| `periodReach` | `ReportsPeriodReach` |
| `incrementalCursor` | `ReadsIncrementally` |

## 3. Add the protocol half

Add an entry under `platforms` in `config/ad_platforms.php` with `label`, `authorize_url`, `token_url`, `api_base`, plus the environment keys for each field the definition declares. `FakeAdsConnector::config()` has the minimum.

## 4. Run the contract

```sh
ch-scripts/ptest.sh <worktree> --filter=ProviderCapabilityContractTest
```

The suite is driven by the catalogue, so your provider is already in every case. It fails, on purpose, until you add your provider's real listing response to `listing()` in that test. It then holds each claim to the code:

| You declared | It proves |
|---|---|
| discovery | rows land in `external_accounts` with your `accountType` |
| a `parentLevel` | those rows carry a parent, and none do if you did not declare one |
| refresh | a `refresh_token` grant is sent with the stored token |
| no refresh | offering a stored refresh token sends no such grant (OAuth 1.0a and API keys excepted) |
| expiry | a renewal path exists, and no renewal without expiry |
| webhooks | a verifier accepts a genuine delivery and refuses a forged one, and refuses with no secret |
| a rate-limit policy | a 429 is retried and the call succeeds |
| an API key | no refresh, no expiry, no scopes, no redirect URI, and the vault asks for nothing |

Then add your provider's row to the runbook table. `test_the_runbook_table_is_the_registry` fails until the row is there and correct.

## Asking about a capability

Never `instanceof` a provider's connector or compare its name:

```php
ProviderCatalogue::supports($provider, ProviderCapability::AbandonedCarts);   // what is declared
$registry->supports($provider, ProviderCapability::Discovery);                // declared AND in this build
$registry->supporting(ProviderCapability::Webhooks);                          // who can
$registry->adapter($provider)?->discover($connection);                        // any family, one door
```

Asking an adapter for something it did not declare throws `CapabilityNotDeclared` before anything is sent.

## Other families

- **Commerce.** Same steps with `ResourceType::Store`, `accountType: 'store'`, a connector extending `ApiCommerceConnector`, a line in `CommerceConnectorRegistry` and `config/commerce_platforms.php`. `StoreDiscovery` and `StoreSyncer` read through the registry, so they should need no edit, **but no test has proved this with a fake store**. `ApiCommerceConnector::api()` also carries a `zid` header branch, which a provider's own connector would override.
- **Analytics.** Not engine-free today. `Ga4PropertyDiscovery`, `Ga4PropertySync` and `SyncMeasurementPropertyJob` are specific to GA4, and `AdvertisingConnectorRegistry::adapter()` maps only `ga4`. A second analytics provider brings its own services and adapter, or the first step is to generalise those.
- **Sheets, CRM, Search Console, GTM, Shopify, WooCommerce.** None exist. `ResourceType` gets a case when one is approved and built (§1.4, §1.5).

Changing `ProviderAdapter` itself is a contract change: bump `CONTRACT_VERSION` and say what existing adapters must now do. `test_the_contract_surface_is_pinned_to_its_version` fails until you do.
