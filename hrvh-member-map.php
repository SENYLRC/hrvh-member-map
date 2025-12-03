<?php
/*
Plugin Name: HRVH Member Map
Description: Displays an HRVH member map using Google Maps and Airtable data.
Version: 5.8
Author: HRVH
*/

if (!defined('ABSPATH')) exit; // Safety: don't allow direct access to the file

// Include the Airtable helper (same folder).
// That file defines hrvh_get_airtable_map_data() which does the server-side
// call to Airtable and returns the decoded JSON array.
require_once __DIR__ . '/D7pqZAx9JtrFw6rt.php';

/* -----------------------------------------------------------
   LOAD CSS SAFELY (not inline)
   - Registers a "fake" stylesheet handle and attaches inline CSS to it.
   - Styling covers the map container and popup appearance.
------------------------------------------------------------ */
add_action('wp_enqueue_scripts', 'hrvh_member_map_css', 20);
function hrvh_member_map_css() {

    // Register an empty handle so we can attach inline CSS to it
    wp_register_style('hrvh-member-map-css', false);
    wp_enqueue_style('hrvh-member-map-css');

    // Basic layout and popup styles for the map
    $css = "
        #hrvh-map {
            width: 1140px;
            height: 800px;
            max-width: 100%;
        }
        #hrvh-map-errors {
            color:#b00000;
            background:#fff3f3;
            padding:10px;
            margin-bottom:10px;
            font-size:14px;
            display:none;
            border:1px solid #e0b4b4;
        }
        .hrvh-popup {
            max-width:420px;
            font-family:Arial,sans-serif;
        }
        .hrvh-popup h3 {
            margin:0 0 5px 0;
            font-size:18px;
        }
        .hrvh-popup .address {
            white-space:pre-line;
            margin-bottom:6px;
        }
        .hrvh-popup .link-block {
            margin:4px 0;
        }
        .hrvh-popup a {
            color:#387180;
            text-decoration:none;
            font-weight:bold;
        }
        .hrvh-logo img {
            max-width:160px;
            display:block;
            margin-top:8px;
        }
    ";

    // Attach the CSS to our handle
    wp_add_inline_style('hrvh-member-map-css', $css);
}

/* -----------------------------------------------------------
   SHORTCODE: [hrvh_member_map]
   - Fetches Airtable data on the server side.
   - Embeds the data into JS (window.HRVH_MAP_DATA).
   - JS then initializes Google Maps and drops pins.
------------------------------------------------------------ */
add_shortcode('hrvh_member_map', function () {

    // 1) Server-side call to Airtable via helper
    //    This NEVER hits Airtable from the browser;
    $airtable = hrvh_get_airtable_map_data();

    // 2) If Airtable fails, show a friendly error instead of a broken map
    if (!$airtable || !isset($airtable['records']) || !is_array($airtable['records'])) {
        return '<div id="hrvh-map-errors" style="color:#b00000;background:#fff3f3;padding:10px;border:1px solid #e0b4b4;">
                    Map data is temporarily unavailable. Please try again later.
                </div>
                <div id="hrvh-map"></div>';
    }

    // 3) Encode Airtable records for safe insertion into JS
    //    This creates a JS array of objects on window.HRVH_MAP_DATA.
    $records_json = wp_json_encode($airtable['records']);

    // 4) Output markup + JS
    ob_start();
    ?>

<div id="hrvh-map-errors"></div>
<div id="hrvh-map">Loading map…</div>

<script>
/* =============================================================================
   CONFIG / CONSTANTS
   - LOGO_BASE: path to logo image files (Airtable stores filename).
   - GEO_CACHE_KEY: key for localStorage used to cache geocodes.
============================================================================= */
const LOGO_BASE     = "https://hrvh.org/wp-content/uploads/maps_logo/";
const GEO_CACHE_KEY = "hrvh_map_geocodes";

// Data injected by PHP (Airtable records array).
// window.HRVH_MAP_DATA = [ { id: "...", fields: {...} }, ... ];
window.HRVH_MAP_DATA = <?php echo $records_json; ?>;

/* =============================================================================
   URL HASH HANDLING
   - Allows direct links like /map/#nmbries to focus a specific site.
   - Hash is cleaned to a simple slug (letters/numbers only).
============================================================================= */
function getCurrentSiteFromHash(){
  const hash = (window.location.hash || "").replace(/^#/, "");
  return hash.toLowerCase().replace(/[^a-z0-9]/g,'');
}
let SITE = getCurrentSiteFromHash();

/* =============================================================================
   UTILITY FUNCTIONS
============================================================================= */

/**
 * Remove "PO Box" lines from address text for cleaner display.
 */
function cleanPO(addr){
  return addr.replace(/po box[^,]*,/i,'').trim();
}

/**
 * Format the address string for display:
 *  - Convert ", " into line breaks
 *  - Clean up NY line formatting
 */
function formatAddress(a){
  a = cleanPO(a);
  return a.replace(/, /g,"\n").replace(/\nNY\n/,'\nNY ');
}

/**
 * Normalize the Map Logo filename into a slug code:
 *  - Lowercase
 *  - Remove spaces, file extension, and non-alphanumeric chars
 * Used for:
 *  - Indexing markers by code
 *  - URL hash identifiers
 */
function cleanCode(raw){
  if (!raw) return "";
  return String(raw).toLowerCase()
    .replace(/\s+/g,'')
    .replace('_logo.jpg','')
    .replace('_logo.png','')
    .replace(/[^a-z0-9]/g,'');
}

/**
 * Show an error in both console AND the #hrvh-map-errors div.
 * Called when geocoding fails or data is missing.
 */
function showError(name, addr, status){
  console.error('❌ Map load failed:', name, '→', addr, 'Reason:', status);

  const err = document.getElementById('hrvh-map-errors');
  if (err) {
    err.style.display = 'block';
    err.innerHTML += `<div>
        ❌ <strong>${name}</strong><br>${addr}<br>
        Reason: ${status}
      </div><hr>`;
  }

  // If the map is still showing the original "Loading map…" message,
  // update it with a more helpful status.
  const mapDiv = document.getElementById('hrvh-map');
  if (mapDiv && mapDiv.innerText.indexOf('Loading map') !== -1) {
    mapDiv.innerText = 'Map data is temporarily unavailable. Please try again later.';
  }
}

/* =============================================================================
   POPUP BUILDER
   - Given a record's fields, build the HTML used in the info window.
============================================================================= */
function buildPopup(name, addr, f){
  // Airtable field names expected
  let dig  = f["Dig Col URL"]       || "",
      news = f["Newspaper URL"]     || "",
      fa   = f["Finding Aid URL"]   || "",
      exh  = f["Exhibit URL"]       || "",
      logo = f["Map Logo"]          || "";

  let logoURL = logo ? (LOGO_BASE + logo) : '';

  let html = `<div class="hrvh-popup">
    <h3>${name}</h3>
    <div class="address">${formatAddress(addr)}</div>`;

  // Conditionally add links if URLs exist
  if(dig)  html += `<div class="link-block"><a href="${dig}" target="_self">Digital Collections</a></div>`;
  if(news) html += `<div class="link-block"><a href="${news}" target="_self">Historic Newspapers</a></div>`;
  if(fa)   html += `<div class="link-block"><a href="${fa}" target="_self">Finding Aids</a></div>`;
  if(exh)  html += `<div class="link-block"><a href="${exh}" target="_self">Online Exhibits</a></div>`;

  // Logo shown at the bottom of the popup (if available)
  if(logoURL){
    html += `<div class="hrvh-logo"><img src="${logoURL}" onerror="this.style.display='none';"></div>`;
  }

  html += `</div>`;
  return html;
}

/* =============================================================================
   GLOBALS
   - map: Google Map instance
   - geocoder: Google Maps Geocoder instance
   - openInfo: currently open InfoWindow (so we can close it before opening another)
   - HRVH_MARKERS: registry from "code" → {marker, info}
============================================================================= */
let map, geocoder, openInfo = null;
const HRVH_MARKERS = {}; // registry of code → { marker, info }

/* =============================================================================
   GEOCODE CACHE
   - Stores address → lat/lng in localStorage.
   - Avoids repeated calls to Google Geocoding API for the same address.
============================================================================= */
function loadGeoCache(){
  try {
    const raw = localStorage.getItem(GEO_CACHE_KEY);
    if (!raw) return {};
    return JSON.parse(raw) || {};
  } catch(e){ return {}; }
}

function saveGeoCache(cache){
  try { localStorage.setItem(GEO_CACHE_KEY, JSON.stringify(cache)); }
  catch(e){}
}

/* =============================================================================
   FOCUS A SITE BY CODE
   - Centers map and opens popup for a given code (derived from Map Logo).
   - Used for:
   - initial hash (#nmbries) handling
   - reacting to hash changes without a full reload
============================================================================= */
function focusHRVHSite(code){
  if (!code) return;
  const data = HRVH_MARKERS[code];
  if (!data || !map) return;

  const pos = data.marker.getPosition();
  if (openInfo) openInfo.close();
  openInfo = data.info;

  map.setCenter(pos);
  map.setZoom(13);
  openInfo.open(map, data.marker);
  console.log("📍 Focusing site", code);
}

// React to hash changes (e.g., user clicks a link that updates #code)
window.addEventListener('hashchange', () => {
  SITE = getCurrentSiteFromHash();
  focusHRVHSite(SITE);
});

/* =============================================================================
   GOOGLE MAPS CALLBACK
   - Called by the Google Maps JS API once it has loaded.
   - Initializes the map and kicks off marker creation.
============================================================================= */
window.initHRVHMap = function() {
  console.log("✅ initHRVHMap called, initial SITE (hash) =", SITE);

  // Initial map center (roughly Hudson Valley)
  map = new google.maps.Map(document.getElementById("hrvh-map"), {
    center: {lat: 41.75, lng: -74.0},
    zoom: 11
  });

  // Geocoder instance used to turn addresses into lat/lng
  geocoder = new google.maps.Geocoder();

  // Use data injected by PHP instead of making an AJAX call
  const records = Array.isArray(window.HRVH_MAP_DATA) ? window.HRVH_MAP_DATA : [];
  if (!records.length) {
    showError("Airtable", "HRVH Member Map", "NO_RECORDS");
    return;
  }

  buildMarkers(records);
};

/* =============================================================================
   CREATE MARKERS
   - Iterates through Airtable records.
   - For each record:
     * Extracts name, address, logo.
     * Geocodes address (or uses cached coordinate).
     * Creates a marker and info window.
   - Uses a recursive "next()" with a timeout to avoid hammering the geocoder.
============================================================================= */
function buildMarkers(recs){
  let i = 0;
  let geoCache = loadGeoCache();

  /**
   * Place a single marker on the map and wire up its InfoWindow + click behavior.
   */
  function placeMarker(name, addr, f, code, pos){
    const marker = new google.maps.Marker({ map, position: pos, title: name });

    const info = new google.maps.InfoWindow({
      content: buildPopup(name,addr,f)
    });

    // Store for later lookup by code (for hash → marker behavior).
    if (code) {
      HRVH_MARKERS[code] = { marker, info };
    }

    // On marker click:
    //  - Close previously open info window.
    //  - Open this marker's info window.
    //  - Update URL hash so user can share a direct link to this marker.
    marker.addListener("click",()=>{
      if(openInfo) openInfo.close();
      openInfo = info;
      info.open(map,marker);

      if (code) {
        window.location.hash = code;
      }
    });
  }

  /**
   * Process the next Airtable record.
   * Uses setTimeout to space out geocoding requests.
   */
  function next(){
    if(i >= recs.length) {
      // When all markers are created, if a hash is present, focus that site.
      if (SITE) {
        focusHRVHSite(SITE);
      }
      return;
    }

    let r = recs[i++], f = r.fields || {};
    let name = f["Organization"]  || "";
    let addr = f["Whole Address"] || "";
    let logo = f["Map Logo"]      || "";
    let code = cleanCode(logo);   // slug based on logo filename

    if(!addr || addr.length < 5){
      showError(name, addr, "EMPTY_ADDRESS");
      return next();
    }

    // Check local cache first to avoid unnecessary geocoding
    const cached = geoCache[addr];
    if(cached){
      placeMarker(name, addr, f, code,
        new google.maps.LatLng(cached.lat, cached.lng));
      return next();
    }

    // No cache → call Google Geocoding API
    geocoder.geocode({address: addr}, (res,status)=>{
      if(status === "OK" && res[0]){
        let pos = res[0].geometry.location;

        // Cache result for future loads
        geoCache[addr] = { lat: pos.lat(), lng: pos.lng() };
        saveGeoCache(geoCache);

        placeMarker(name, addr, f, code, pos);
      } else {
        showError(name, addr, status);
      }
      // Slight delay to throttle requests
      setTimeout(next, 50);
    });
  }

  // Start processing records
  next();
}
</script>

<!-- Google Maps loader (calls initHRVHMap when ready) -->
<script
  src="https://maps.googleapis.com/maps/api/js?key=<Your GOOGLE API KEY>&callback=initHRVHMap&loading=async"
  async defer>
</script>

<?php
    return ob_get_clean();
});
