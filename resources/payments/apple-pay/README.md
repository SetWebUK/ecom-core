# Apple Pay domain association (Apple Pay through PayPal)

Apple fetches `/.well-known/apple-developer-merchantid-domain-association` from every domain registered for Apple Pay.
With Apple Pay through PayPal the file is PayPal's, one for each environment and the same for every PayPal merchant:

- `paypal-sandbox`: https://paypalobjects.com/devdoc/apple-pay/sandbox/apple-developer-merchantid-domain-association
- `paypal-live`: https://paypalobjects.com/devdoc/apple-pay/well-known/apple-developer-merchantid-domain-association

Downloaded 23 Oct 2026 (PayPal's copies last modified 13 May 2024). `ApplePayDomainController` serves the one matching
the PayPal sandbox switch. If PayPal ever replaces them, download the new files over these.
