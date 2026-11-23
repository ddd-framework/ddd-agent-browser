// Own autoconsent rules: pages where the upstream rules of
// @duckduckgo/autoconsent leave the banner on screen. Same JSON format as the package's rules/rules.json; a rule with
// runContext.urlPattern is site-specific and is tried before every generic rule (autoconsent findCmp), so it wins over
// a generic rule that also matches the page. Names end in "(own)" so the answer's consent.cmp shows they are ours.

export const OWN_CONSENT_RULES = [
  {
    // TK runs its own consent dialog (web component tkds-dialog) on top of Tealium. The upstream Tealium rule sets the
    // Tealium consent to "no" and reports success, but the dialog stays open. "Nur erforderliche" closes it and stores
    // the refusal (cookies tkConsent…=0, CONSENTMGR consent:false); the dialog is open while the body carries
    // is-display-consentmanager. The button is a custom element whose host takes the click.
    name: 'tk.de (own)',
    runContext: { urlPattern: '^https://(www\\.)?tk\\.de/' },
    prehideSelectors: [],
    detectCmp: [{ exists: 'tkds-dialog.consent-manager' }],
    detectPopup: [{ exists: 'body.is-display-consentmanager' }],
    optIn: [{ waitForThenClick: 'tkds-button.consent-manager__approve-all-button', timeout: 5000 }],
    optOut: [
      { waitForThenClick: 'tkds-button.consent-manager__approve-required-button', timeout: 5000 },
      // the refusal is stored in a cookie the page reads on the next visit; the body class goes later than that
      { wait: 1000 },
      { cookieContains: 'tkConsentSettingsSaved=' },
    ],
  },
  {
    // kleinanzeigen.de sends a first visit to a full consent page /gdpr?redirectTo=… (no dialog, no known platform).
    // "Alle ablehnen und fortfahren" (two copies, top and bottom) stores the refusal and redirects to the target page;
    // the renderer waits for that page (ConsentHandler.settle).
    name: 'kleinanzeigen.de (own)',
    runContext: { urlPattern: '^https://(www\\.)?kleinanzeigen\\.de/gdpr' },
    prehideSelectors: [],
    detectCmp: [{ exists: '#consentManagementPage' }],
    detectPopup: [{ visible: '#consentManagementPage' }],
    optIn: [{ waitForThenClick: "#consentManagementPage button[aria-label^='Einverstanden']", timeout: 5000 }],
    optOut: [{ waitForThenClick: "#consentManagementPage button[aria-label='Datenschutzbestimmungen und Einstellungen ablehnen']", timeout: 5000 }],
  },
];
