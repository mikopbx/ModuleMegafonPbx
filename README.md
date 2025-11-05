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

Save the changes.

The sync script uses the module’s saved connection settings and the sync period.

### How it works
- The module queries the MegaPBX History API and fetches events for a period.
- Records are inserted into MikoPBX CDR; duplicate protection is applied.
- The module is read‑only and does not modify data in MegaPBX.

### Export to 1C:Enterprise
- Call history imported to MikoPBX can be exported to 1C by standard means.
- The repository includes `getHistory.epf` for fetching history from MikoPBX into 1C.

### Useful links
- MegaPBX CRM API (History): [api.megapbx.ru](https://api.megapbx.ru/#/docs/crmapi/v1/history#history-period)
- Megafon VATS REST API: [vats.megafon.ru/rest_api](https://vats.megafon.ru/rest_api)

### Support
- Questions and bug reports — via the project’s issue tracker.
- License: see `LICENSE`.
