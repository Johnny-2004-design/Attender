/* ==========================================================================
   ATTENDER — frontend deployment config (the ONLY file to edit before upload)
   --------------------------------------------------------------------------
   apiBase       Full URL of the PHP API front controller on the backend host
                 (InfinityFree), ending in /api/index.php. HTTPS, no trailing slash.
                 Leave "" for same-origin installs (XAMPP / single host): the URL
                 is then derived from this site's own location, as before.
   apiExtraQuery Extra query string appended to every API call. "i=1" lets browser
                 requests through InfinityFree's anti-bot interstitial. Harmless
                 elsewhere; set "" on any other host.
   No secrets belong here — this file is public.
   ========================================================================== */
window.ATTENDER_CONFIG = {
  apiBase: 'https://attendease.freepage.cc/api/index.php',
  apiExtraQuery: 'i=1'
};
