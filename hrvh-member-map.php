
<?php
/*
Plugin Name: HRVH Member Map
Description: Displays an HRVH member map using Google Maps and Airtable data.
Version: 5.7
Author: HRVH
*/

if (!defined('ABSPATH')) exit;

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

ob_start();
?>

<div id="hrvh-map-errors"></div>
<div id="hrvh-map">Loading map…</div>

<script>
/* =============================================================================
   CONFIG
============================================================================= */
const AIRTABLE_URL  = "/wp-content/plugins/hrvh-member-map/proxy.php";
const LOGO_BASE     = "https://hrvh.org/wp-content/uploads/maps_logo/";
const GEO_CACHE_KEY = "hrvh_map_geocodes";

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

  // Also update the map div so it's obvious something went wrong
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
      fa   = f["Finding Aid URL"]   || f["Finding Air URL"] || "",
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
// registry of code → { marker, info }
const HRVH_MARKERS = {};

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
  loadAirtable();
};

/* =============================================================================
   LOAD AIRTABLE
============================================================================= */
function loadAirtable(){

  const cacheKey     = "hrvh_map_cache";
  const cacheTimeKey = "hrvh_map_cache_time";
  const maxAge       = 1000 * 60 * 60 * 24; // 24 hours

  const now        = Date.now();
  const cached     = localStorage.getItem(cacheKey);
  const cachedTime = localStorage.getItem(cacheTimeKey);

  if(cached && cachedTime && (now - cachedTime < maxAge)){
    console.log("⚡ Using cached Airtable");
    try {
      const parsed = JSON.parse(cached);
      buildMarkers(parsed.records || []);
    } catch(e){
      console.warn("Cached Airtable data was invalid, refetching...", e);
      localStorage.removeItem(cacheKey);
      localStorage.removeItem(cacheTimeKey);
      loadAirtable(); // retry once without cache
    }
    return;
  }

  fetch(AIRTABLE_URL)
    .then(r => {
      if (!r.ok) {
        // Proxy returned a non-2xx HTTP code
        throw new Error("Proxy HTTP " + r.status + " " + r.statusText);
      }
      return r.json();
    })
    .then(d => {
      if (!d || !Array.isArray(d.records)) {
        throw new Error("Proxy responded but records array is missing");
      }
      localStorage.setItem(cacheKey, JSON.stringify(d));
      localStorage.setItem(cacheTimeKey, now);
      buildMarkers(d.records);
    })
    .catch(err => {
      showError("Airtable Proxy", AIRTABLE_URL, err && err.message ? err.message : err);
    });
}

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

    // register for later hash-based focusing
    if (code) {
      HRVH_MARKERS[code] = { marker, info };
    }

    marker.addListener("click",()=>{
      if(openInfo) openInfo.close();
      openInfo = info;
      info.open(map,marker);

      // Update URL hash so you can copy/share it
      if (code) {
        window.location.hash = code;
      }
    });
  }

  function next(){
    if(i >= recs.length) {
      // Once all markers are created, if we have a SITE from hash, focus it
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
  src="https://maps.googleapis.com/maps/api/js?key=<Google API Key>&callback=initHRVHMap&loading=async"
  async defer>
</script>

<?php
return ob_get_clean();
});
