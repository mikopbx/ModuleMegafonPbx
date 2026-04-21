## ModuleMegafonPbx — import MegaPBX call history to MikoPBX

Brief:

- **Purpose**: import call history from MegaPBX into MikoPBX CDR.
- **Next**: from MikoPBX you can export call history to 1C:Enterprise (see `getHistory.epf`).
- **Limitation**: works only with MegaPBX; the module is read‑only and does not control calls.

### Requirements
- Access to MegaPBX API (CRM API) and a valid API key/credentials.
- Installed MikoPBX with permissions to install and run modules.

### Installation
1. In the MikoPBX web UI go to: Modules → Module Marketplace → Upload new module.
2. Select the `ModuleMegafonPbx` archive and wait for installation to finish.
3. Activate the module.

### Configuration
In the module settings specify:
- **Megafon PBX address**: e.g. `vats.megafon.ru`.
- **API key for authorization** in MegaPBX.
- **Which number to display in history** — internal or employee mobile number.
- **Time offset** — shift record time by the specified hours.
- **CRM token** — secret string used to authenticate incoming webhook events from
  MegaPBX (must match the token configured in the MegaPBX cabinet, see below).

Save the changes.

The sync script uses the module’s saved connection settings and the sync period.

### How it works
- The module queries the MegaPBX History API and fetches events for a period.
- Records are inserted into MikoPBX CDR; duplicate protection is applied.
- The module is read‑only and does not modify data in MegaPBX.

### Incoming webhook from MegaPBX

Besides the periodic pull, the module exposes a public REST endpoint that
MegaPBX VATS can push events to (real‑time call notifications, history records
with recording link, contact lookups for popup cards):

```
POST https://<your-pbx>/pbxcore/mega-pbx/event
Content-Type: application/x-www-form-urlencoded   (or application/json)
```

Authentication is done via the `crm_token` field in the request body
(must match the **CRM token** from the module settings).

Supported `cmd` values:
- `event`   — call state notifications (INCOMING / ACCEPTED / COMPLETED / …);
              forwarded to 1C via SOAP if `ModuleCTIClient` is configured,
              otherwise written to the file log.
- `history` — final call record with mp3 link; accepted with `200 ok` and
              ignored, since the cron worker `bin/synchCdr.php` already loads
              the same history via `/crmapi/v1/history/json`.
- `contact` — client lookup by phone for a popup card on the IP phone.
              Returns `{"contact_name": "..."}` if `ModuleCTIClient` /
              its CRM daemon are available, `{}` otherwise.

To enable webhook delivery in MegaPBX:
1. Open the MegaPBX customer cabinet → Integrations → CRM API.
2. Set the **CRM URL** to `https://<your-pbx>/pbxcore/mega-pbx/event`.
3. Set the **CRM token** to the same value you saved in the module settings.

Logs are written with rotation (40 MB × 9 files) to:
```
/storage/usbdisk1/mikopbx/log/ModuleMegafonPbx/EventController.log
```

### Security & firewall

The webhook endpoint is **public** (no Bearer/cookie auth, only `crm_token`).
At minimum:

- **Use HTTPS only.** Without TLS the `crm_token` is sent in plain text.
- **Restrict the source IP on the firewall.** MegaPBX delivers webhooks from
  a known address — for example, traffic on the production VATS goes from
  `193.201.230.155`. Configure your firewall / nginx allow‑list so that
  `/pbxcore/mega-pbx/*` accepts only this address (and any other documented
  MegaPBX outbound IPs of your installation; verify the current list with
  the operator's support).
- **Use a strong, random `crm_token`** (e.g. UUIDv4) and rotate it periodically.
- **Monitor `auth_fail` entries** in the EventController log — repeated failures
  indicate a brute‑force attempt.

### Export to 1C:Enterprise
- Call history imported to MikoPBX can be exported to 1C by standard means.
- The repository includes `getHistory.epf` for fetching history from MikoPBX into 1C.

### Useful links
- MegaPBX CRM API (History): [api.megapbx.ru](https://api.megapbx.ru/#/docs/crmapi/v1/history#history-period)
- Megafon VATS REST API: [vats.megafon.ru/rest_api](https://vats.megafon.ru/rest_api)

### Support
- Questions and bug reports — via the project’s issue tracker.
- License: see `LICENSE`.
