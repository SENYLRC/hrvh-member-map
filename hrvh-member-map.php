<?php
/*
Plugin Name: HRVH Member Map
Description: Displays an HRVH member map using Google Maps and Airtable data.
Version: 5.8
Author: HRVH
*/

if (!defined('ABSPATH')) exit;

// Include the Airtable helper (same folder)
require_once __DIR__ . '/proxy.php';

/* -----------------------------------------------------------
   LOAD CSS SAFELY (not inline)
------------------------------------------------------------ */
add_action('wp_enqueue_scripts', 'hrvh_member_map_css', 20);
function hrvh_member_map_css() {

    wp_register_style('hrvh-member-map-css', false);
    wp_enqueue_style('hrvh-member-map-css');

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

    wp_add_inline_style('hrvh-member-map-css', $css);
}

/* -----------------------------------------------------------
   SHORTCODE
------------------------------------------------------------ */
add_shortcode('hrvh_member_map', function () {

    // Server-side call to Airtable via helper
    $airtable = hrvh_get_airtable_map_data();

    if (!$airtable || !isset($airtable['records']) || !is_array($airtable['records'])) {
        // Fail gracefully
        return '<div id="hrvh-map-errors" style="color:#b00000;background:#fff3f3;padding:10px;border:1px solid #e0b4b4;">
                    Map data is temporarily unavailable. Please try again later.
                </div>
                <div id="hrvh-map"></div>';
    }

    // Use wp_json_encode for safety
    $records_json = wp_json_encode($airtable['records']);

    ob_start();
    ?>

<div id="hrvh-map-errors"></div>
<div id="hrvh-map">Loading map…</div>

<script>
/* =============================================================================
   CONFIG
============================================================================= */
const LOGO_BASE     = "https://hrvh.org/wp-content/uploads/maps_logo/";
const GEO_CACHE_KEY = "hrvh_map_geocodes";

// Data injected by PHP
window.HRVH_MAP_DATA = <?php echo $records_json; ?>;

// Read site from hash: /map/#nmbries
function getCurrentSiteFromHash(){
  const hash = (window.location.hash || "").replace(/^#/, "");
  return hash.toLowerCase().replace(/[^a-z0-9]/g,'');
}
let SITE = getCurrentSiteFromHash();

/* =============================================================================
   UTILITY FUNCTIONS
============================================================================= */
function cleanPO(addr){
  return addr.replace(/po box[^,]*,/i,'').trim();
}

function formatAddress(a){
  a = cleanPO(a);
  return a.replace(/, /g,"\n").replace(/\nNY\n/,'\nNY ');
}

function cleanCode(raw){
  if (!raw) return "";
  return String(raw).toLowerCase()
    .replace(/\s+/g,'')
    .replace('_logo.jpg','')
    .replace('_logo.png','')
    .replace(/[^a-z0-9]/g,'');
}

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

  const mapDiv = document.getElementById('hrvh-map');
  if (mapDiv && mapDiv.innerText.indexOf('Loading map') !== -1) {
    mapDiv.innerText = 'Map data is temporarily unavailable. Please try again later.';
  }
}

/* =============================================================================
   POPUP BUILDER
============================================================================= */
function buildPopup(name, addr, f){
  let dig  = f["Dig Col URL"]       || "",
      news = f["Newspaper URL"]     || "",
      fa   = f["Finding Aid URL"]   || "",
      exh  = f["Exhibit URL"]       || "",
      logo = f["Map Logo"]          || "";

  let logoURL = logo ? (LOGO_BASE + logo) : '';

  let html = `<div class="hrvh-popup">
    <h3>${name}</h3>
    <div class="address">${formatAddress(addr)}</div>`;

  if(dig)  html += `<div class="link-block"><a href="${dig}" target="_self">Digital Collections</a></div>`;
  if(news) html += `<div class="link-block"><a href="${news}" target="_self">Newspapers</a></div>`;
  if(fa)   html += `<div class="link-block"><a href="${fa}" target="_self">Finding Aids</a></div>`;
  if(exh)  html += `<div class="link-block"><a href="${exh}" target="_self">Exhibits</a></div>`;

  if(logoURL){
    html += `<div class="hrvh-logo"><img src="${logoURL}" onerror="this.style.display='none';"></div>`;
  }

  html += `</div>`;
  return html;
}

/* =============================================================================
   GLOBALS
============================================================================= */
let map, geocoder, openInfo = null;
const HRVH_MARKERS = {}; // registry of code → { marker, info }

/* =============================================================================
   GEOCODE CACHE
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

/* React to hash changes (e.g., user clicks another link without reload) */
window.addEventListener('hashchange', () => {
  SITE = getCurrentSiteFromHash();
  focusHRVHSite(SITE);
});

/* =============================================================================
   GOOGLE CALLBACK (must be on window)
============================================================================= */
window.initHRVHMap = function() {
  console.log("✅ initHRVHMap called, initial SITE (hash) =", SITE);

  map = new google.maps.Map(document.getElementById("hrvh-map"), {
    center: {lat: 41.75, lng: -74.0},
    zoom: 11
  });

  geocoder = new google.maps.Geocoder();

  // Use server-injected data instead of fetching via AJAX
  const records = Array.isArray(window.HRVH_MAP_DATA) ? window.HRVH_MAP_DATA : [];
  if (!records.length) {
    showError("Airtable", "HRVH Member Map", "NO_RECORDS");
    return;
  }

  buildMarkers(records);
};

/* =============================================================================
   CREATE MARKERS
============================================================================= */
function buildMarkers(recs){
  let i = 0;
  let geoCache = loadGeoCache();

  function placeMarker(name, addr, f, code, pos){
    const marker = new google.maps.Marker({ map, position: pos, title: name });

    const info = new google.maps.InfoWindow({
      content: buildPopup(name,addr,f)
    });

    if (code) {
      HRVH_MARKERS[code] = { marker, info };
    }

    marker.addListener("click",()=>{
      if(openInfo) openInfo.close();
      openInfo = info;
      info.open(map,marker);

      if (code) {
        window.location.hash = code;
      }
    });
  }

  function next(){
    if(i >= recs.length) {
      if (SITE) {
        focusHRVHSite(SITE);
      }
      return;
    }

    let r = recs[i++], f = r.fields || {};
    let name = f["Organization"]  || "";
    let addr = f["Whole Address"] || "";
    let logo = f["Map Logo"]      || "";
    let code = cleanCode(logo);

    if(!addr || addr.length < 5){
      showError(name, addr, "EMPTY_ADDRESS");
      return next();
    }

    const cached = geoCache[addr];
    if(cached){
      placeMarker(name, addr, f, code,
        new google.maps.LatLng(cached.lat, cached.lng));
      return next();
    }

    geocoder.geocode({address: addr}, (res,status)=>{
      if(status === "OK" && res[0]){
        let pos = res[0].geometry.location;

        geoCache[addr] = { lat: pos.lat(), lng: pos.lng() };
        saveGeoCache(geoCache);

        placeMarker(name, addr, f, code, pos);
      } else {
        showError(name, addr, status);
      }
      setTimeout(next, 50);
    });
  }

  next();
}
</script>

<!-- Google Maps loader -->
<script
  src="https://maps.googleapis.com/maps/api/js?key=<your google API Key>&callback=initHRVHMap&loading=async"
  async defer>
</script>

<?php
    return ob_get_clean();
});
