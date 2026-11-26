/* ===== Utilities ===== */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const app = $("#app");
const esc = (s) =>
  String(s ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
const E = new Proxy({ Dog: "🐕", Cat: "🐈", Rabbit: "🐰" }, { get: (t, k) => (typeof k === "string" ? t[k.trim().charAt(0).toUpperCase() + k.trim().slice(1).toLowerCase()] : undefined) });

/* Use the current date instead of a fixed demo date. */
/* "Today" always follows Philippine time (Asia/Manila), whatever the computer's own clock/timezone is. */
const TODAY = (() => {
  try {
    const p = Object.fromEntries(new Intl.DateTimeFormat("en-GB", { timeZone: "Asia/Manila", hourCycle: "h23", year: "numeric", month: "numeric", day: "numeric", hour: "numeric", minute: "numeric", second: "numeric" }).formatToParts(new Date()).map((x) => [x.type, x.value]));
    return new Date(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second);
  } catch (e) { return new Date(); }
})();
const MONTHS = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];
const WEEKDAYS = [
  "Sunday",
  "Monday",
  "Tuesday",
  "Wednesday",
  "Thursday",
  "Friday",
  "Saturday",
];
const iso = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const parse = (s) => {
  const [y, m, d] = s.split("-").map(Number);
  return new Date(y, m - 1, d);
};
const TODAY_ISO = iso(TODAY);
const fmtDate = (s) => {
  if (!s) return "—";
  const d = parse(s);
  return `${MONTHS[d.getMonth()].slice(0, 3)} ${d.getDate()}, ${d.getFullYear()}`;
};
const fmtLong = (d) =>
  `${WEEKDAYS[d.getDay()]}, ${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
const fmtTime = (t) => {
  if (!t) return "—";
  const [h, m] = t.split(":").map(Number);
  return `${h % 12 || 12}:${String(m).padStart(2, "0")} ${h < 12 ? "AM" : "PM"}`;
};
const plural = (n, w) => `${n} ${w}${n === 1 ? "" : "s"}`;

const BADGE = {
  Vaccinated: "g",
  "Up-to-Date": "g",
  Confirmed: "g",
  Active: "g",
  Available: "g",
  Healthy: "g",
  "Due Soon": "y",
  Pending: "y",
  "In Progress": "b",
  Completed: "k",
  Overdue: "r",
  Cancelled: "r",
  Inactive: "r",
  Adopted: "b",
  "No Records": "k",
};
const VAX_ICON = { "Up-to-Date": "✓ ", "Due Soon": "⏰ ", Overdue: "🚨 " };
const badge = (v, icon) =>
  `<span class="badge ${BADGE[v] || ""}">${icon ? VAX_ICON[v] || "" : ""}${esc(v)}</span>`;

const VACCINES = [
  "Rabies",
  "Distemper",
  "Parvo",
  "FVRCP",
  "Leptospirosis",
  "RHDV",
  "Myxomatosis",
  "Bordetella",
];
const APPT_STATUS = [
  "Confirmed",
  "Pending",
  "In Progress",
  "Completed",
  "Cancelled",
];

/* ===== Empty application data (no demo/sample records) ===== */
const D = {
  pets: [],
  appointments: [],
  vaccinations: [],
  adoption: [],
  users: [],
  doctor: null,
  slots: [],
  settings: {
    name: "",
    email: "",
    phone: "",
    address: "",
  },
};

/* ===== Derived values (statuses are calculated from dates, never typed in) ===== */
const vaxStatus = (v) => {
  const d = Math.round((parse(v.next) - TODAY) / 864e5);
  return d < 0 ? "Overdue" : d <= 30 ? "Due Soon" : "Up-to-Date";
};
const petOf = (n) => D.pets.find((p) => p.name === n) || {};
const petOfRec = (r) => D.pets.find((p) => r.petId && p.id === r.petId) || petOf(r.pet);
const petVax = (p) => {
  const s = D.vaccinations.filter((v) => v.petId === p.id).map(vaxStatus);
  return !s.length
    ? "No Records"
    : s.includes("Overdue")
      ? "Overdue"
      : s.includes("Due Soon")
        ? "Due Soon"
        : "Vaccinated";
};
const tile = (species, i = 0) =>
  `<span class="tile t${i % 5}">${E[species] || "🐾"}</span>`;
const petTile = (p, i = 0) =>
  p && p.photo
    ? `<span class="tile t${i % 5} has-img"><img src="${esc(p.photo)}" alt="" loading="lazy" onerror="this.parentNode.classList.remove('has-img');this.replaceWith(document.createTextNode('${E[p.species] || "🐾"}'))"></span>`
    : tile(p && p.species, i);
const byTime = (a, b) => (a.date + a.time).localeCompare(b.date + b.time);

let state = {
  user: null,
  page: "dashboard",
  q: "",
  f: "",
  st: "",
  vc: "",
  pg: 1,
  tab: "Available",
  sel: TODAY_ISO,
  cal: { y: TODAY.getFullYear(), m: TODAY.getMonth() },
};

/* ===== Login screen ===== */
const PAW = (c) =>
  `<svg viewBox="0 0 24 24" width="28" height="28" fill="${c}" aria-hidden="true"><ellipse cx="6" cy="10" rx="2.2" ry="2.8" transform="rotate(-20 6 10)"/><ellipse cx="10.5" cy="5.8" rx="2.2" ry="2.8"/><ellipse cx="15.5" cy="6.4" rx="2.2" ry="2.8" transform="rotate(12 15.5 6.4)"/><ellipse cx="19.2" cy="11" rx="2" ry="2.6" transform="rotate(28 19.2 11)"/><path d="M12.4 11c-3 0-6 3.4-6 6.2 0 2 1.6 2.9 3.4 2.6 1.1-.2 1.7-.5 2.6-.5s1.5.3 2.6.5c1.8.3 3.4-.6 3.4-2.6 0-2.8-3-6.2-6-6.2z"/></svg>`;
const ICON = {
  user: '<svg viewBox="0 0 24 24" width="18" height="18" fill="#4c2f7a"><circle cx="12" cy="7.5" r="4.2"/><path d="M4 21c0-4.4 3.6-7.2 8-7.2s8 2.8 8 7.2z"/></svg>',
  lock: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none"><rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2" fill="#f2b84b"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" stroke="#8a97a8" stroke-width="2.2" stroke-linecap="round"/><circle cx="12" cy="15.7" r="1.6" fill="#a8641a"/></svg>',
  eye: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
  eyeOff:
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 5.1A9.6 9.6 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.5C3.8 8.3 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.4 4.5-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>',
  signin:
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none"><rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2" fill="#f5c26b"/><path d="M8 10.5V8a4 4 0 0 1 7.6-1.7" stroke="#e2e8f0" stroke-width="2.2" stroke-linecap="round"/><circle cx="12" cy="15.7" r="1.6" fill="#a8641a"/></svg>',
};
const PETS_SVG = `<svg viewBox="0 0 230 175" width="230" height="175" aria-hidden="true">
  <g fill="#e6f2ef">
    <ellipse cx="46" cy="118" rx="36" ry="30"/><ellipse cx="116" cy="124" rx="34" ry="28"/><ellipse cx="186" cy="124" rx="34" ry="30"/>
    <ellipse cx="20" cy="66" rx="12" ry="19" transform="rotate(14 20 66)"/><ellipse cx="72" cy="66" rx="12" ry="19" transform="rotate(-14 72 66)"/>
    <ellipse cx="46" cy="76" rx="30" ry="27"/>
    <path d="M88 74 100 42l16 16 16-16 12 32z"/><ellipse cx="116" cy="88" rx="31" ry="26"/>
    <ellipse cx="172" cy="48" rx="8" ry="26" transform="rotate(-6 172 48)"/><ellipse cx="200" cy="48" rx="8" ry="26" transform="rotate(6 200 48)"/>
    <ellipse cx="186" cy="94" rx="28" ry="25"/>
  </g>
  <g fill="#f6b8c4"><ellipse cx="172" cy="50" rx="3.6" ry="18" transform="rotate(-6 172 50)"/><ellipse cx="200" cy="50" rx="3.6" ry="18" transform="rotate(6 200 50)"/>
    <ellipse cx="46" cy="87" rx="7" ry="5"/><ellipse cx="116" cy="96" rx="5" ry="3.6"/><ellipse cx="186" cy="102" rx="5" ry="3.6"/></g>
  <g fill="#0f4d47"><ellipse cx="37" cy="74" rx="3.4" ry="3"/><ellipse cx="55" cy="74" rx="3.4" ry="3"/>
    <ellipse cx="105" cy="86" rx="3.4" ry="3"/><ellipse cx="127" cy="86" rx="3.4" ry="3"/>
    <ellipse cx="176" cy="92" rx="3.2" ry="3"/><ellipse cx="196" cy="92" rx="3.2" ry="3"/></g>
  <g fill="#8fd0c3"><circle cx="40" cy="162" r="4"/><circle cx="52" cy="162" r="4"/><circle cx="46" cy="155" r="3.4"/>
    <circle cx="110" cy="164" r="4"/><circle cx="122" cy="164" r="4"/><circle cx="116" cy="157" r="3.4"/>
    <circle cx="180" cy="162" r="4"/><circle cx="192" cy="162" r="4"/><circle cx="186" cy="155" r="3.4"/></g>
</svg>`;

let authMode = 'login';
async function authRequest(action, data = {}) {
  const response = await fetch('api/auth.php?action=' + encodeURIComponent(action), {
    method: action === 'me' ? 'GET' : 'POST',
    headers: {'Content-Type': 'application/json'},
    credentials: 'same-origin',
    body: action === 'me' ? undefined : JSON.stringify(data)
  });
  const result = await response.json().catch(() => ({success:false,message:'Invalid server response.'}));
  if (!response.ok || !result.success) throw new Error(result.message || 'Request failed.');
  return result;
}
function login() {
  app.innerHTML = `<div class="login">
    <section class="lp"><i class="bub b1"></i><i class="bub b2"></i><div class="lp-in">
      <div class="lp-brand"><span class="lp-logo">${PAW("#3b2f5e")}</span><span><b>Olongapo City</b><small>Veterinary Office</small></span></div>
      <div class="lp-art">${PETS_SVG}</div><h2>Caring for Every Companion</h2>
      <p>Professional veterinary care management for the animals of Olongapo City.</p>
      <div class="lp-stats"><div><b>Pet Care</b><small>Records</small></div><div><b>Clinic</b><small>Management</small></div><div><b>Secure</b><small>Accounts</small></div></div>
    </div></section>
    <section class="rp"><i class="bub b3"></i><form id="lf" novalidate>
      <div class="f-logo">${PAW("#3b2f5e")}</div><h1 id="auth-title">Welcome Back</h1>
      <p class="sub" id="auth-sub">Sign in to access the Veterinary Management System</p>
      <div id="signup-fields" class="hidden">
        <label>Full name<span class="field"><i>${ICON.user}</i><input name="name" autocomplete="name" placeholder="Enter your full name" maxlength="120"></span></label>
        <label>Email<span class="field"><i>${ICON.user}</i><input name="email" type="email" autocomplete="email" placeholder="Enter your email"></span></label>
      </div>
      <label>Username<span class="field"><i>${ICON.user}</i><input name="u" autocomplete="username" placeholder="Enter your username" maxlength="50" required></span></label>
      <label>Password<span class="field"><i>${ICON.lock}</i><input name="p" type="password" autocomplete="current-password" placeholder="Enter your password" required minlength="8"><button type="button" class="eye" id="eye" aria-label="Show password">${ICON.eye}</button></span></label>
      <label id="confirm-wrap" class="hidden">Confirm password<span class="field"><i>${ICON.lock}</i><input name="confirm" type="password" autocomplete="new-password" placeholder="Re-enter your password"></span></label>
      <div class="opts" id="login-options"><label class="chk"><input type="checkbox" name="r"> Remember me</label><a href="#" id="fp">Forgot password?</a></div>
      <div class="err" id="err" role="alert"></div><button class="signin" id="submit-auth">${ICON.signin} Sign In</button>
      <p class="sub" style="margin-bottom:0"><span id="switch-label">Don't have an account?</span> <a href="#" id="switch-auth" style="color:var(--p);font-weight:600;text-decoration:none">Create account</a></p>
      <div class="notice">${ICON.lock.replace('width="17" height="17"', 'width="13" height="13"')} Passwords are stored securely using server-side hashing.</div>
    </form></section><button class="help" id="help" aria-label="Help">?</button></div>`;
  const f = $('#lf');
  function setMode(mode) {
    authMode = mode;
    const signup = mode === 'signup';
    $('#auth-title').textContent = signup ? 'Create Account' : 'Welcome Back';
    $('#auth-sub').textContent = signup ? 'Register for the Veterinary Management System' : 'Sign in to access the Veterinary Management System';
    $('#signup-fields').classList.toggle('hidden', !signup);
    $('#confirm-wrap').classList.toggle('hidden', !signup);
    $('#login-options').classList.toggle('hidden', signup);
    $('#submit-auth').innerHTML = `${ICON.signin} ${signup ? 'Create Account' : 'Sign In'}`;
    $('#switch-label').textContent = signup ? 'Already have an account?' : "Don't have an account?";
    $('#switch-auth').textContent = signup ? 'Sign in' : 'Create account';
    f.p.autocomplete = signup ? 'new-password' : 'current-password';
    $('#err').textContent = '';
  }
  $('#switch-auth').onclick = e => { e.preventDefault(); setMode(authMode === 'login' ? 'signup' : 'login'); };
  $('#eye').onclick = () => { const show=f.p.type==='password'; f.p.type=show?'text':'password'; $('#eye').innerHTML=show?ICON.eyeOff:ICON.eye; };
  $('#fp').onclick = e => { e.preventDefault(); $('#err').textContent='Please contact the system administrator to reset your password.'; };
  $('#help').onclick = () => alert('Need help? Contact your system administrator.');
  f.onsubmit = async e => {
    e.preventDefault(); const err=$('#err'), btn=$('#submit-auth'); err.textContent='';
    const username=f.u.value.trim(), password=f.p.value;
    if (!username || !password) { err.textContent='Please enter your username and password.'; return; }
    if (authMode==='signup') {
      if (!f.name.value.trim() || !f.email.value.trim()) { err.textContent='Please enter your full name and email.'; return; }
      if (password.length < 8) { err.textContent='Password must be at least 8 characters.'; return; }
      if (password !== f.confirm.value) { err.textContent='Passwords do not match.'; return; }
    }
    btn.disabled=true; btn.textContent=authMode==='signup'?'Creating account…':'Signing in…';
    try {
      const result = await authRequest(authMode==='signup'?'register':'login', authMode==='signup' ? {name:f.name.value.trim(),email:f.email.value.trim(),username,password} : {username,password});
      state.user = result.user.username; state.account = result.user;
      if (state.account.role === 'Pet Owner') await loadOwnerData();
      render();
    } catch (error) { err.textContent=error.message; btn.disabled=false; btn.innerHTML=`${ICON.signin} ${authMode==='signup'?'Create Account':'Sign In'}`; }
  };
  setMode('login');
}

/* ===== App shell ===== */
const NAV = [
  ["MAIN MENU"],
  ["dashboard", "📊", "Dashboard"],
  ["pets", "🐾", "Pet Records"],
  ["appointments", "📅", "Appointments"],
  ["availability", "🗓️", "Availability"],
  ["vaccinations", "💉", "Vaccinations"],
  ["adoption", "🏠", "Adoption"],
  ["SYSTEM"],
  ["users", "👥", "Users"],
  ["settings", "⚙️", "Settings"],
  ["profile", "👤", "My Profile"],
];
const NAV_LABEL = Object.fromEntries(
  NAV.filter((n) => n[1]).map((n) => [n[0], n[2]]),
);

function who() {
  const name0 = state.user || "user",
    u = D.users.find((x) => x.username === name0.toLowerCase());
  const name = (state.account && state.account.name) || (u ? u.name : name0[0].toUpperCase() + name0.slice(1)),
    role = (state.account && state.account.role) || (u ? u.role : "Veterinary Staff");
  const parts = name.split(/\s+/);
  return {
    name,
    role,
    avatar: (state.account && state.account.avatar_path) || '',
    short: parts[0] === "Dr." ? "Dr. " + parts[parts.length - 1] : parts[0],
    ini: (parts.length > 1
      ? parts[0][0] + parts[parts.length - 1][0]
      : parts[0].slice(0, 2)
    ).toUpperCase(),
  };
}

function buildShell() {
  const me = who();
  app.innerHTML = `<div class="app">
    <aside class="sb">
      <div class="sb-brand"><span class="sb-logo">${PAW("#fff")}</span><span><b>Olongapo City</b><small>Veterinary Office</small></span></div>
      <nav>${NAV.map((n) => (n[1] ? `<button class="nav" data-go="${n[0]}"><i>${n[1]}</i>${n[2]}</button>` : `<div class="sb-label">${n[0]}</div>`)).join("")}</nav>
      <div class="sb-user"><span class="av">${me.avatar ? `<img src="${esc(me.avatar)}" alt="">` : esc(me.ini)}</span><span class="sb-ut"><b>${esc(me.short)}</b><small>${esc(me.role)}</small></span><button data-act="logout" title="Log out" aria-label="Log out">🚪</button></div>
    </aside>
    <div class="ov" data-menu="1"></div>
    <div class="mn">
      <header class="hd">
        <button class="burger" data-menu="1" aria-label="Menu">☰</button>
        <div class="hd-t"><h1 id="ttl"></h1><small>${fmtLong(TODAY)}</small></div>
        <div class="hd-r">
          <div class="qs"><span>🔍</span><input id="qs" placeholder="Quick search..." aria-label="Quick search"></div>
          <button class="bell" data-act="bell" aria-label="Notifications">🔔<i></i></button>
          <div class="me"><span class="av">${me.avatar ? `<img src="${esc(me.avatar)}" alt="">` : esc(me.ini)}</span><span class="me-t"><b>${esc(me.short)}</b><small>${esc(me.role)}</small></span></div>
        </div>
      </header>
      <main class="ct" id="content"></main>
    </div></div>`;
}

const PAGES = {
  dashboard: pDash,
  pets: pPets,
  appointments: pAppts,
  availability: pAvail,
  vaccinations: pVax,
  adoption: pAdopt,
  users: pUsers,
  settings: pSettings,
  profile: pProfile,
};

async function loadAppointmentsFromDatabase() {
  const response = await fetch('api/appointments.php?action=list', {credentials:'same-origin'});
  const result = await response.json().catch(() => ({success:false,message:'Invalid server response.'}));
  if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load appointments.');
  D.appointments = (result.appointments || []).map(a => ({
    dbId: Number(a.id), petId: Number(a.pet_id), veterinarianId: Number(a.veterinarian_id || 0), pet: a.pet_name || 'Unknown pet',
    date: String(a.appointment_date || '').slice(0,10), time: String(a.appointment_time || '').slice(0,5),
    purpose: a.purpose || '', status: a.status || 'Pending', vet: a.veterinarian || 'Not assigned',
    owner: a.owner_name || '—', ownerEmail: a.owner_email || '', address: a.address || '—', phone: a.owner_phone || '',
    notes: a.notes || '', by: a.cancelled_by || ''
  }));
  state.apptDbLoaded = true; state.apptLoadError = '';
}
/* ===== Clinic records are stored in the database (api/records.php), never only in the browser ===== */
const RECORD_PAGES = ["dashboard", "pets", "vaccinations", "adoption", "settings"];
async function apiJson(url, body) {
  const response = await fetch(url, body === undefined ? { credentials: "same-origin" } : (body instanceof FormData ? { method: "POST", credentials: "same-origin", body } : { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }));
  const result = await response.json().catch(() => ({ success: false, message: "Invalid server response." }));
  if (!response.ok || !result.success) throw new Error(result.message || "Request failed.");
  return result;
}
const recordRequest = (resource, action, body) => apiJson(`api/records.php?resource=${resource}&action=${action}`, body);
async function loadRecordsFromDatabase() {
  try {
    const [pets, vax, adopt, meta, settings] = await Promise.all([
      apiJson("api/records.php?resource=pets"), apiJson("api/records.php?resource=vaccinations"), apiJson("api/records.php?resource=adoption"),
      apiJson("api/records.php?resource=meta"), apiJson("api/records.php?resource=settings"),
    ]);
    D.pets = (pets.pets || []).map((p) => ({ id: Number(p.id), name: p.name || "", species: p.species || "", breed: p.breed || "", owner: p.owner_name || "", ownerEmail: p.owner_email || "", phone: p.owner_phone || "", address: p.address || "", age: p.age || "", gender: p.gender || "Unknown", notes: p.notes || "", photo: p.photo_path || "", added: String(p.created_at || "").slice(0, 10) }));
    D.vaccinations = (vax.vaccinations || []).map((v) => ({ id: Number(v.id), petId: Number(v.pet_id), pet: v.pet_name || "", vaccine: v.vaccine || "", given: String(v.date_given || "").slice(0, 10), next: String(v.next_due || v.date_given || "").slice(0, 10), vet: v.vet_name || "Not recorded" }));
    D.adoption = (adopt.adoption || []).map((a) => ({ id: Number(a.id), name: a.pet_name || "", species: a.species || "", breed: a.breed || "", age: a.age || "", gender: a.gender || "Unknown", health: a.health_status || "Unknown", status: a.status || "Available", reason: a.reason || "", adopter: a.adopter_name || "" }));
    D.doctor = meta.doctor || null;
    D.slots = meta.slots || [];
    Object.assign(D.settings, { name: "", email: "", phone: "", address: "" }, settings.settings || {});
    await loadAppointmentsFromDatabase();
    state.recordsError = "";
  } catch (e) { state.recordsError = e.message; }
  state.recordsLoaded = true;
}
const ensureRecords = () => (state.recordsLoaded && !state.recordsStale ? Promise.resolve() : loadRecordsFromDatabase());
async function updateAppointmentStatus(id, status) {
  const response = await fetch('api/appointments.php?action=status', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status})});
  const result = await response.json().catch(() => ({success:false,message:'Invalid server response.'}));
  if (!response.ok || !result.success) throw new Error(result.message || 'Unable to update appointment.');
  await loadAppointmentsFromDatabase(); render();
  toast(status === 'Confirmed' ? 'Appointment approved. The owner was notified.' : status === 'Cancelled' ? 'Appointment rejected. The owner was notified.' : status === 'Completed' ? 'Appointment completed. The owner was notified.' : 'Appointment status updated.');
}

/* ===== Availability: weekly days off, Philippine holidays, doctor unavailable / closures ===== */
async function availRequest(action, body, query = '') {
  const response = await fetch('api/closures.php?action=' + action + query, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined });
  const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
  if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load availability.');
  return result;
}
/* Philippine calendar marks (holidays, weekly days off, doctor away) for the Appointments calendar */
function loadHolidays(mk) {
  state.holiLoading = { ...(state.holiLoading || {}), [mk]: 1 };
  availRequest('calendar', null, '&month=' + mk)
    .then((r) => { state.holi = { ...(state.holi || {}), [mk]: r.days || {} }; })
    .catch(() => { state.holi = { ...(state.holi || {}), [mk]: {} }; })
    .finally(() => {
      delete state.holiLoading[mk];
      const cur = `${state.cal.y}-${String(state.cal.m + 1).padStart(2, "0")}`;
      if (state.page === 'appointments' && cur === mk && !state.apptLoading) pAppts();
    });
}
function pAvail() {
  if (!state.avail && !state.availError && !state.availLoading) {
    state.availLoading = true;
    $("#content").innerHTML = '<div class="card">Loading availability…</div>';
    availRequest('list').then((r) => { state.avail = r; }).catch((e) => { state.availError = e.message; })
      .finally(() => { state.availLoading = false; if (state.page === 'availability') pAvail(); });
    return;
  }
  if (state.availLoading) return;
  if (!state.avail) {
    $("#content").innerHTML = `<div class="card"><h3>Unable to load availability</h3><p class="err">${esc(state.availError)}</p><button class="btn" data-act="avail-reload">Try again</button></div>`;
    return;
  }
  const A = state.avail, R = A.rules, canEdit = A.can_edit;
  const when = (c) => (c.start_time ? `${fmtTime(c.start_time)} – ${fmtTime(c.end_time)}` : "Whole day");
  const closures = A.closures.length
    ? table(["Date", "Type", "Time", "Reason", ...(canEdit ? ["Actions"] : [])],
        A.closures.map((c) => `<tr>${cell("Date", `<b>${fmtDate(c.closure_date)}</b>`)}${cell("Type", esc(c.type))}${cell("Time", when(c))}${cell("Reason", esc(c.reason || "—"))}${canEdit ? cell("Actions", `<div class="ac sm"><button class="btn red" data-act="avail-del" data-i="${c.id}">Remove</button></div>`) : ""}</tr>`).join(""))
    : empty("No custom closures. Add a day when the doctor is away or the clinic is closed.");
  const holidays = table(["Date", "Holiday", "Type"], A.holidays.map((h) => `<tr>${cell("Date", `<b>${fmtDate(h.date)}</b>`)}${cell("Holiday", esc(h.name))}${cell("Type", esc(h.type))}</tr>`).join(""));
  $("#content").innerHTML = `
    <div class="top"><p class="mu">Pet owners cannot book closed days, holidays or times when the doctor is unavailable.</p>${canEdit ? '<button class="btn" data-act="avail-add">+ Add Closure / Doctor Unavailable</button>' : ""}</div>
    <div class="card"><h3>Booking Rules</h3><p class="mu">Open ${fmtTime(R.open)} – ${fmtTime(R.close)} · ${R.slot_minutes}-minute appointments · lunch break ${fmtTime(R.lunch_start)} – ${fmtTime(R.lunch_end)}<br>Clinic is closed every <b>${esc(R.closed_days.length > 1 ? R.closed_days.slice(0, -1).join(", ") + " and " + R.closed_days.slice(-1) : R.closed_days[0] || "—")}</b> and on the Philippine holidays listed below. Change these in <code>api/helpers.php</code>.</p></div>
    <h3 style="margin:26px 0 12px">Doctor Unavailable &amp; Closures</h3>${closures}
    <h3 style="margin:26px 0 6px">Philippine Holidays (automatic)</h3><p class="mu" style="margin:0 0 12px">Eid’l Fitr, Eid’l Adha, Chinese New Year and any holiday declared later by the President or the City change every year. Add those above as a closure.</p>${holidays}`;
}
function openClosureForm() {
  const m = modal(`<form><h2>Add Closure / Doctor Unavailable</h2><p class="mu">Owners can no longer book the blocked time. Owners who already have an appointment then are notified to reschedule.</p>
    <div class="fg"><label>Date<input type="date" name="date" min="${TODAY_ISO}" required></label>
    <label>Type<select name="type"><option>Doctor Unavailable</option><option>Holiday</option><option>Clinic Closed</option><option>Other</option></select></label>
    <label class="full" style="flex-direction:row;align-items:center;gap:10px"><input type="checkbox" name="whole" checked style="width:auto;min-height:0"> Whole day</label>
    <label id="cf-from" style="display:none">From<input type="time" name="start" value="09:00"></label><label id="cf-to" style="display:none">To<input type="time" name="end" value="17:00"></label>
    <label class="full">Reason (optional)<input name="reason" maxlength="190" placeholder="e.g. Veterinarian on leave, Eid’l Fitr"></label></div>
    <div class="err" id="ferr"></div><div class="acts"><button type="button" class="btn ghost" data-x>Cancel</button><button class="btn" id="cf-save">Save</button></div></form>`);
  const f = $("form", m), err = $("#ferr", m);
  f.elements.whole.onchange = () => { const d = f.elements.whole.checked ? "none" : "flex"; $("#cf-from", m).style.display = d; $("#cf-to", m).style.display = d; };
  f.onsubmit = async (e) => {
    e.preventDefault(); err.textContent = ""; const btn = $("#cf-save", m); btn.disabled = true;
    try {
      const r = await availRequest('add', { date: f.elements.date.value, type: f.elements.type.value, whole: f.elements.whole.checked, start: f.elements.start.value, end: f.elements.end.value, reason: f.elements.reason.value });
      m.remove(); state.avail = null; state.availError = ""; state.holi = null; state.apptDbLoaded = false; pAvail(); toast(r.message);
    } catch (er) { err.textContent = er.message; btn.disabled = false; }
  };
}

/* Staff action buttons for one appointment (all changes are saved in the database and the pet owner is notified). */
function apptActions(a) {
  if (!a.dbId) return `<small>Clinic action: ${esc(a.status)}</small>`;
  const b = (act, label, cls = "", title = "") => `<button class="btn ${cls}" data-act="${act}" data-i="${a.dbId}" title="${title || label}">${label}</button>`;
  const isVet = state.account?.role === 'Veterinarian' && Number(a.veterinarianId) === Number(state.account.id);
  const report = isVet && a.status !== 'Cancelled' ? b('medical-report', '🩺 Medical Report', 'ghost') : '';
  if (a.status === "Pending") return b("approve-appointment", "✓ Approve", "", "Approve appointment") + b("decline-appointment", "Reject", "red", "Reject appointment") + b("reschedule-appointment", "🔄 Reschedule", "ghost") + report;
  if (a.status === "Confirmed") return b("start-appointment", "▶ Start", "ghost") + report + b("reschedule-appointment", "🔄 Reschedule", "ghost");
  if (a.status === "In Progress") return report + b("reschedule-appointment", "🔄 Reschedule", "ghost");
  return report || `<small>Clinic action: ${esc(a.status)}</small>`;
}
function openMedicalReport(dbId) {
  const a = D.appointments.find(x => x.dbId === dbId);
  if (!a) return;
  if (state.account?.role !== 'Veterinarian' || Number(a.veterinarianId) !== Number(state.account.id)) return alert('Only the assigned veterinarian can submit this report.');
  const m = modal(`<form><h2>Veterinarian Medical Report</h2><p class="mu"><b>${esc(a.pet)}</b> · ${esc(fmtDate(a.date))} at ${esc(fmtTime(a.time))}</p>
    <div class="fg">
      <label>Diagnosis<textarea name="diagnosis" required maxlength="5000" rows="3"></textarea></label>
      <label>Symptoms<textarea name="symptoms" required maxlength="5000" rows="3"></textarea></label>
      <label>Observations<textarea name="observations" maxlength="5000" rows="3"></textarea></label>
      <label>Treatment<textarea name="treatment" required maxlength="5000" rows="3"></textarea></label>
      <label>Medication<textarea name="medication" maxlength="5000" rows="3"></textarea></label>
      <label>Recommendations<textarea name="recommendations" maxlength="5000" rows="3"></textarea></label>
      <label>Follow-up date<input type="date" name="follow_up_date" min="${TODAY_ISO}"></label>
    </div><div class="err" id="report-error"></div><div class="acts"><button type="button" class="btn ghost" data-x>Cancel</button><button class="btn" id="report-save">Save Report &amp; Complete Appointment</button></div></form>`);
  const f=$('form',m), err=$('#report-error',m), btn=$('#report-save',m);
  f.onsubmit=async e=>{
    e.preventDefault(); btn.disabled=true; err.textContent='Saving…';
    try {
      const response=await fetch('api/medical_reports.php?action=save',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({appointment_id:dbId,...Object.fromEntries(new FormData(f))})});
      const result=await response.json().catch(()=>({success:false,message:'Invalid server response.'}));
      if(!response.ok||!result.success) throw new Error(result.message||'Unable to save report.');
      m.remove(); await loadAppointmentsFromDatabase(); render(); toast(result.message);
    } catch(ex) { err.textContent=ex.message; btn.disabled=false; }
  };
}
async function apptSlotRequest(date, excludeId) {
  const response = await fetch('api/appointments.php?action=slots', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({date, exclude_id: excludeId})});
  const result = await response.json().catch(() => ({success:false,message:'Invalid server response.'}));
  if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load time slots.');
  return result;
}
function openReschedule(dbId) {
  const a = D.appointments.find(x => x.dbId === dbId);
  if (!a) return;
  const m = modal(`<form><h2>Reschedule Appointment</h2><p class="mu"><b>${esc(a.pet)}</b> · ${esc(a.owner)}<br>Currently ${fmtDate(a.date)} at ${fmtTime(a.time)}. The pet owner will be notified of the new time.</p>
    <div class="fg"><label>New date<input type="date" name="date" min="${TODAY_ISO}" value="${a.date >= TODAY_ISO ? a.date : TODAY_ISO}" required></label>
    <label>Available time<select name="time" required><option value="">Loading…</option></select></label></div>
    <div class="err" id="ferr"></div><div class="acts"><button type="button" class="btn ghost" data-x>Cancel</button><button class="btn" id="rs-save">Reschedule &amp; notify owner</button></div></form>`);
  const f = $("form", m), sel = f.elements.time, err = $("#ferr", m);
  async function load() {
    err.textContent = ""; sel.innerHTML = '<option value="">Loading…</option>';
    try {
      const r = await apptSlotRequest(f.elements.date.value, dbId);
      if (!r.open) { sel.innerHTML = `<option value="">${esc(r.label || 'Clinic closed or past date')}</option>`; err.textContent = 'The clinic is not accepting appointments on that date.'; return; }
      sel.innerHTML = '<option value="">Select a time</option>' + r.slots.map(s => `<option value="${s.time}" ${s.available ? "" : "disabled"}>${fmtTime(s.time)}${s.available ? "" : " — unavailable"}</option>`).join("");
    } catch (e) { sel.innerHTML = '<option value="">—</option>'; err.textContent = e.message; }
  }
  f.elements.date.onchange = load; load();
  f.onsubmit = async e => {
    e.preventDefault();
    if (!sel.value) { err.textContent = "Choose an available time."; return; }
    const btn = $("#rs-save", m); btn.disabled = true; err.textContent = "";
    try {
      const response = await fetch('api/appointments.php?action=reschedule', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id: dbId, date: f.elements.date.value, time: sel.value})});
      const result = await response.json().catch(() => ({success:false,message:'Invalid server response.'}));
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to reschedule.');
      m.remove(); await loadAppointmentsFromDatabase(); state.sel = f.elements.date.value; render(); toast(result.message);
    } catch (er) { err.textContent = er.message; btn.disabled = false; load(); }
  };
}

function render() {
  if (!state.user) return login();
  if (state.account?.role === "Pet Owner") { if (!ownerPageNames[state.page]) state.page = 'owner-home'; return renderOwnerDashboard(); }
  if (!$(".app")) buildShell();
  const isAdmin = state.account?.role === 'Administrator';
  const usersNav = $('.nav[data-go="users"]');
  if (usersNav) usersNav.classList.toggle('hidden', !isAdmin);
  if (state.page === 'users' && !isAdmin) state.page = 'dashboard';
  $("#ttl").textContent = NAV_LABEL[state.page];
  $$(".nav").forEach((b) =>
    b.classList.toggle("on", b.dataset.go === state.page),
  );
  if (state.page === 'users' && state.account?.role === 'Administrator' && !state.usersLoading) {
    state.usersLoading = true;
    $('#content').innerHTML = '<div class="card">Loading registered users…</div>';
    loadUsersFromDatabase().finally(() => {
      state.usersLoading = false;
      if (state.page === 'users') {
        if (state.usersLoadError) $('#content').innerHTML = `<div class="card"><h3>Unable to load users</h3><p class="err">${esc(state.usersLoadError)}</p><button class="btn" data-act="reload-users">Try again</button></div>`;
        else PAGES.users();
      }
    });
    return;
  }
  if (state.page === 'users' && state.account?.role !== 'Administrator') {
    state.page = 'dashboard';
    return render();
  }
  if (state.page === 'appointments' && !state.apptDbLoaded && !state.apptLoading) {
    state.apptLoading = true;
    $('#content').innerHTML = '<div class="card">Loading appointments from the database…</div>';
    loadAppointmentsFromDatabase().catch(err => { state.apptLoadError = err.message; }).finally(() => {
      state.apptLoading = false;
      if (state.page === 'appointments') {
        if (state.apptLoadError) $('#content').innerHTML = `<div class="card"><h3>Unable to load appointments</h3><p class="err">${esc(state.apptLoadError)}</p><button class="btn" data-act="reload-appointments">Try again</button></div>`;
        else PAGES.appointments();
      }
    });
    return;
  }
  if (RECORD_PAGES.includes(state.page)) {
    if ((!state.recordsLoaded || state.recordsStale) && !state.recordsLoading) {
      state.recordsLoading = true;
      $('#content').innerHTML = '<div class="card">Loading records from the database…</div>';
      loadRecordsFromDatabase().finally(() => { state.recordsLoading = false; state.recordsStale = false; if (RECORD_PAGES.includes(state.page)) render(); });
      return;
    }
    if (state.recordsLoading) return;
    if (state.recordsError) { $('#content').innerHTML = `<div class="card"><h3>Unable to load records</h3><p class="err">${esc(state.recordsError)}</p><button class="btn" data-act="reload-records">Try again</button></div>`; return; }
  }
  PAGES[state.page]();
}
function go(p, q = "") {
  if (p !== state.page && typeof window.showAppLoading === 'function') {
    const role = state.account?.role === 'Pet Owner' ? 'pet owner' : state.account?.role === 'Administrator' ? 'administrator' : 'clinic';
    window.showAppLoading(`Opening ${ownerPageNames[p] || NAV_LABEL[p] || 'page'} for your ${role} workspace`);
  }
  if (p === "appointments") state.apptDbLoaded = false;
  if (RECORD_PAGES.includes(p)) state.recordsStale = true;
  if (p === "availability") { state.avail = null; state.availError = ""; }
  Object.assign(state, {
    page: p,
    q,
    f: "",
    st: "",
    vc: "",
    pg: 1,
    tab: "Available",
    sel: TODAY_ISO,
    cal: { y: TODAY.getFullYear(), m: TODAY.getMonth() },
  });
  $(".app")?.classList.remove("open");
  render();
  window.scrollTo(0, 0);
  // Keep the transition visible long enough to be noticed, then reveal the page.
  window.setTimeout(() => { if (typeof window.finishAppLoading === 'function') window.finishAppLoading(); }, 320);
}
function refresh() {
  const a = document.activeElement,
    id = a && a.id,
    p = a && a.selectionStart;
  PAGES[state.page]();
  if (id) {
    const n = document.getElementById(id);
    if (n) {
      n.focus();
      try {
        n.setSelectionRange(p, p);
      } catch (e) {}
    }
  }
}
function toast(m) {
  let t = $(".toast");
  if (!t) {
    t = document.createElement("div");
    t.className = "toast";
    document.body.append(t);
  }
  t.textContent = m;
  t.classList.add("show");
  clearTimeout(toast.t);
  toast.t = setTimeout(() => t.classList.remove("show"), 2800);
}

/* ===== Small UI helpers ===== */
const acts = (k, i) =>
  `<div class="ac"><button data-act="view" data-k="${k}" data-i="${i}" title="View">👁️</button><button data-act="edit" data-k="${k}" data-i="${i}" title="Edit">✏️</button><button class="dl" data-act="del" data-k="${k}" data-i="${i}" title="Delete">🗑️</button></div>`;
const search = (ph) =>
  `<div class="sr"><span>🔍</span><input id="q" data-s="q" placeholder="${ph}" value="${esc(state.q)}" autocomplete="off"></div>`;
const chip = (key, val, label) =>
  `<button class="chip ${state[key] === val ? "on" : ""}" data-set="${key}:${val}">${label}</button>`;
const sel = (key, opts, all) =>
  `<select class="sl" data-s="${key}" aria-label="${all}"><option value="">${all}</option>${opts.map((o) => `<option ${state[key] === o ? "selected" : ""}>${esc(o)}</option>`).join("")}</select>`;
function paginate(rows, size) {
  const pages = Math.max(1, Math.ceil(rows.length / size));
  state.pg = Math.min(Math.max(1, state.pg), pages);
  const a = (state.pg - 1) * size,
    items = rows.slice(a, a + size);
  const foot = `<div class="foot"><span>${rows.length ? `Showing ${a + 1}–${a + items.length} of ${rows.length} records` : "No records"}</span><div class="pager"><button data-pg="${state.pg - 1}" ${state.pg <= 1 ? "disabled" : ""} aria-label="Previous">‹</button>${Array.from({ length: pages }, (_, i) => `<button class="${i + 1 === state.pg ? "on" : ""}" data-pg="${i + 1}">${i + 1}</button>`).join("")}<button data-pg="${state.pg + 1}" ${state.pg >= pages ? "disabled" : ""} aria-label="Next">›</button></div></div>`;
  return { items, foot };
}
const table = (heads, body, foot = "", cls = "") =>
  `<div class="card tb"><div class="wrap"><table class="rt ${cls}"><thead><tr>${heads.map((h) => `<th>${h}</th>`).join("")}</tr></thead><tbody>${body}</tbody></table></div>${foot}</div>`;
const empty = (m) => `<div class="empty">🐾<br>${m}</div>`;
const cell = (label, html, cls = "") =>
  `<td data-label="${label}" class="${cls}">${html}</td>`;

/* ===== Dashboard ===== */
function pDash() {
  const me = who();
  const todays = D.appointments
    .filter((a) => a.date === TODAY_ISO)
    .sort(byTime);
  const vax = D.vaccinations.map((v) => ({ ...v, status: vaxStatus(v) }));
  const due = vax.filter((v) => v.status === "Due Soon"),
    over = vax.filter((v) => v.status === "Overdue");
  const avail = D.adoption.filter((a) => a.status === "Available").length,
    pend = D.adoption.filter((a) => a.status === "Pending").length;
  const newPets = D.pets.filter(
    (p) => (TODAY - parse(p.added)) / 864e5 <= 30,
  ).length;
  const inProg = todays.filter((a) => a.status === "In Progress").length;
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
  const stats = [
    [
      "🐾",
      "g",
      D.pets.length,
      "Total Pets",
      `+${newPets} this month`,
      pct(
        D.pets.filter((p) => petVax(p) === "Vaccinated").length,
        D.pets.length,
      ),
    ],
    [
      "📅",
      "p",
      todays.length,
      "Today's Appointments",
      `${inProg} in progress`,
      pct(
        todays.filter(
          (a) => a.status === "Confirmed" || a.status === "In Progress",
        ).length,
        todays.length,
      ),
    ],
    [
      "💉",
      "o",
      due.length + over.length,
      "Vaccinations Due",
      `${over.length} overdue`,
      pct(over.length, due.length + over.length),
    ],
    [
      "🏠",
      "k",
      avail,
      "Available for Adoption",
      `${pend} pending review`,
      pct(avail, D.adoption.length),
    ],
  ];
  const recent = [...D.pets]
    .sort((a, b) => b.added.localeCompare(a.added))
    .slice(0, 4);
  const remind = vax
    .filter((v) => v.status !== "Up-to-Date")
    .sort((a, b) => a.next.localeCompare(b.next))
    .slice(0, 5);
  const head = (t, go, label) =>
    `<div class="ch"><h3>${t}</h3><button class="btn sm" data-go="${go}">${label}</button></div>`;
  $("#content").innerHTML = `
    <section class="hero"><div><small>Good Day,</small><h2>${esc(me.short)} 👋</h2><p>You have <b>${plural(todays.length, "appointment")}</b> today and <b>${plural(over.length, "overdue vaccination")}</b> to review.</p></div>
      <div class="hero-r"><span class="paws">🐾</span><div class="hero-date"><b>${MONTHS[TODAY.getMonth()]} ${TODAY.getDate()}, ${TODAY.getFullYear()}</b><span>${WEEKDAYS[TODAY.getDay()]}</span></div></div></section>
    <section class="g4">${stats.map((s) => `<div class="card stat"><div class="stat-t"><span class="ic ic-${s[1]}">${s[0]}</span><em class="tag tag-${s[1]}">${s[4]}</em></div><b>${s[2]}</b><small>${s[3]}</small><div class="bar"><i class="bar-${s[1]}" style="width:${s[5]}%"></i></div></div>`).join("")}</section>
    <section class="g3">
      <div class="card">${head("Today's Appointments", "appointments", "View All")}${
        todays.length
          ? todays
              .slice(0, 5)
              .map(
                (a, i) =>
                  `<div class="li"><span class="tile t${i}">${E[petOfRec(a).species] || "🐾"}</span><div class="li-m"><span><b>${esc(a.pet)}</b> <em>· ${esc(petOfRec(a).owner || "—")}</em></span><small>${esc(a.purpose)}</small></div><div class="li-r"><b class="or">${fmtTime(a.time)}</b><small>${esc(ovVet(a.vet))}</small></div></div>`,
              )
              .join("")
          : empty("No appointments today.")
      }</div>
      <div class="card">${head("Recently Added Pets", "pets", "View All")}${recent.map((p, i) => `<div class="li">${petTile(p, i)}<div class="li-m"><b>${esc(p.name)}</b><small>${esc(p.breed)} · ${esc(p.owner)}</small></div><div class="li-r"><small>${fmtDate(p.added)}</small></div></div>`).join("")}
        <div class="sum"><div><b>${D.pets.length}</b><small>Total Pets</small></div><div><b>${new Set(D.pets.map((p) => (p.owner || "").toLowerCase()).filter(Boolean)).size}</b><small>Owners</small></div><div><b>${new Set(D.pets.map((p) => (p.species || "").trim().toLowerCase()).filter(Boolean)).size}</b><small>Animal Types</small></div></div></div>
      <div class="card">${head("Vaccination Reminders", "vaccinations", "Manage")}
        <div class="two"><div class="mini y"><b>${due.length}</b><small>Due Soon</small></div><div class="mini r"><b>${over.length}</b><small>Overdue</small></div></div>
        ${remind.length ? remind.map((v) => `<div class="li"><span class="tile t4">💉</span><div class="li-m"><b>${esc(v.pet)}</b><small>${esc(v.vaccine)} · ${esc(petOfRec(v).owner || "—")}</small></div><div class="li-r">${badge(v.status)}<small>${fmtDate(v.next)}</small></div></div>`).join("") : empty("All vaccinations are up to date.")}</div>
    </section>`;
}

/* ===== Pet Records ===== */
function pPets() {
  const q = state.q.toLowerCase();
  const rows = D.pets
    .map((p, i) => ({ ...p, i, vax: petVax(p) }))
    .filter(
      (p) =>
        (!state.st || p.vax === state.st) &&
        (!q || [p.name, p.owner, p.breed].join(" ").toLowerCase().includes(q)),
    );
  const { items, foot } = paginate(rows, 6);
  $("#content").innerHTML = `
    <div class="top"><p class="mu">${plural(D.pets.length, "pet")} registered</p><button class="btn" data-act="add" data-k="pets">+ Add New Pet</button></div>
    <div class="card bar2">${search("Search by pet name, owner, or breed...")}${sel("st", ["Vaccinated", "Due Soon", "Overdue", "No Records"], "All Statuses")}</div>
    ${rows.length ? table(["Pet", "Animal Type", "Breed", "Owner", "Age", "Gender", "Vax Status", "Actions"], items.map((p) => `<tr>${cell("Pet", `<div class="who">${petTile(p, p.i)}<b>${esc(p.name)}</b></div>`)}${cell("Species", esc(p.species))}${cell("Breed", esc(p.breed))}${cell("Owner", esc(p.owner))}${cell("Age", esc(p.age))}${cell("Gender", `<span class="${p.gender === "Male" ? "male" : "female"}">${p.gender === "Male" ? "♂" : "♀"} ${esc(p.gender)}</span>`)}${cell("Vax Status", badge(p.vax))}${cell("Actions", acts("pets", p.i))}</tr>`).join(""), foot) : `<div class="card">${empty("No pets found.")}</div>`}`;
}

/* ===== Appointments ===== */
const DOT = {
  Confirmed: "g",
  Pending: "o",
  "In Progress": "b",
  Cancelled: "r",
  Completed: "k",
};
function pAppts() {
  const q = state.q.toLowerCase(),
    { y, m } = state.cal;
  const list = D.appointments
    .map((a, i) => ({
      ...a,
      i,
      owner: a.owner || petOfRec(a).owner || "—",
      address: a.address || petOfRec(a).address || "Olongapo City",
    }))
    .filter(
      (a) =>
        !q ||
        [a.pet, a.owner, a.purpose, a.vet, a.status]
          .join(" ")
          .toLowerCase()
          .includes(q),
    );
  const pad = new Date(y, m, 1).getDay(),
    days = new Date(y, m + 1, 0).getDate();
  const mk = `${y}-${String(m + 1).padStart(2, "0")}`,
    ph = (state.holi || {})[mk] || {};
  if (!(state.holi || {})[mk] && !(state.holiLoading || {})[mk]) loadHolidays(mk);
  const MARK = { holiday: "Holiday", closed: "Closed", unavailable: "Away", partial: "Partial" };
  let cells = "";
  for (let i = 0; i < pad; i++) cells += '<span class="day blank"></span>';
  for (let d = 1; d <= days; d++) {
    const key = iso(new Date(y, m, d)),
      ap = list.filter((a) => a.date === key),
      mark = ph[key];
    cells += `<button class="day ${ap.length ? "has" : ""} ${mark ? "off-" + mark.state : ""} ${key === state.sel ? "sel" : ""} ${key === TODAY_ISO ? "today" : ""}" data-day="${key}" ${mark ? `title="${esc(mark.label || "")}"` : ""} aria-label="${fmtDate(key)}: ${plural(ap.length, "appointment")}${mark ? ", " + esc(mark.label || MARK[mark.state]) : ""}"><b>${d}</b><span class="dots">${ap
      .slice(0, 3)
      .map((a) => `<i class="d-${DOT[a.status]}"></i>`)
      .join("")}</span>${mark ? `<small class="dn">${MARK[mark.state]}</small>` : ""}</button>`;
  }
  const notes = Object.entries(ph).filter(([, v]) => v.state !== "closed").map(([k, v]) => `<li><b>${+k.slice(8)}</b> ${esc(v.label || MARK[v.state])}</li>`).join("");
  const sm = ((state.holi || {})[state.sel.slice(0, 7)] || {})[state.sel];
  const day = list.filter((a) => a.date === state.sel).sort(byTime),
    sd = parse(state.sel);
  const ws = new Date(sd);
  ws.setDate(sd.getDate() - sd.getDay());
  const we = new Date(ws);
  we.setDate(ws.getDate() + 6);
  const wk = D.appointments.filter(
    (a) => a.date >= iso(ws) && a.date <= iso(we),
  );
  const n = (s) => wk.filter((a) => a.status === s).length;
  $("#content").innerHTML = `
    <div class="top end">${search("Search appointments...")}<button class="btn" data-act="add" data-k="appointments">+ New Appointment</button></div>
    <div class="cal-wrap">
      <div class="card cal"><div class="ch"><h3>${MONTHS[m]} ${y}</h3><div class="nv"><button data-cal="-1" aria-label="Previous month">‹</button><button data-cal="1" aria-label="Next month">›</button></div></div>
        <div class="wd">${["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map((w) => `<span>${w}</span>`).join("")}</div><div class="days">${cells}</div>
        <div class="legend"><span><i class="d-g"></i>Scheduled</span><span><i class="d-b"></i>In Progress</span><span><i class="d-o"></i>Pending</span><span><i class="d-r"></i>Cancelled</span><span><i class="d-h"></i>Holiday / off / doctor away</span></div>${notes ? `<ul class="hlist"><li class="hl-t">Holidays &amp; closures this month</li>${notes}</ul>` : ""}</div>
      <div class="side">
        <div class="card"><h3>${state.sel === TODAY_ISO ? "Today's Schedule" : "Schedule"}</h3><small class="mu">${fmtDate(state.sel)} — ${plural(day.length, "appointment")}</small>${sm ? `<div class="hnote">${sm.state === "closed" ? "🔒" : "🎌"} <b>${esc(sm.label || MARK[sm.state])}</b> — ${sm.state === "holiday" ? "Philippine holiday, clinic closed" : sm.state === "closed" ? "weekly day off" : sm.state === "partial" ? "doctor unavailable for part of the day" : "doctor / clinic unavailable"}</div>` : ""}
          <div class="tl">${day.length ? day.map((a) => `<div class="tli"><i class="d-${DOT[a.status]}"></i><div class="tc"><div class="tc-t"><b>${fmtTime(a.time)}</b>${badge(a.status)}${a.status === "Cancelled" && a.by ? `<small class="mu"> by ${a.by === "Owner" ? "owner" : "clinic"}</small>` : ""}</div><strong>${esc(a.pet)}</strong><p><b>Owner:</b> ${esc(a.owner)}</p><p><b>Address:</b> ${esc(a.address)}</p><small>${esc(a.purpose)} • ${esc(ovVet(a.vet))}</small><div class="ac sm">${apptActions(a)}</div></div></div>`).join("") : empty("No appointments on this day.")}</div></div>
        <div class="card"><h3>This Week Summary</h3><div class="wk"><div class="wr g"><span>Total Appointments</span><b>${wk.length}</b></div><div class="wr g"><span>Completed</span><b>${n("Completed")}</b></div><div class="wr y"><span>Pending</span><b>${n("Pending")}</b></div><div class="wr r"><span>Cancelled</span><b>${n("Cancelled")}</b></div></div></div>
      </div></div>`;
}
function moveMonth(d) {
  const c = new Date(state.cal.y, state.cal.m + d, 1);
  state.cal = { y: c.getFullYear(), m: c.getMonth() };
  state.sel =
    TODAY.getFullYear() === c.getFullYear() && TODAY.getMonth() === c.getMonth()
      ? TODAY_ISO
      : iso(c);
  refresh();
}

/* ===== Vaccinations ===== */
function pVax() {
  const q = state.q.toLowerCase();
  const all = D.vaccinations.map((v, i) => ({
    ...v,
    i,
    status: vaxStatus(v),
    owner: petOfRec(v).owner || "—",
  }));
  const rows = all
    .filter(
      (v) =>
        (!state.st || v.status === state.st) &&
        (!state.vc || v.vaccine === state.vc) &&
        (!q || [v.pet, v.owner, v.vaccine].join(" ").toLowerCase().includes(q)),
    )
    .sort((a, b) => a.i - b.i);
  const c = (s) => all.filter((v) => v.status === s).length;
  const { items, foot } = paginate(rows, 8);
  const sc = (s, cls, ic) =>
    `<button class="card sc ${cls} ${state.st === s ? "on" : ""}" data-set="st:${state.st === s ? "" : s}"><span><b>${c(s)}</b><strong>${s}</strong><small>Click to filter</small></span><i>${ic}</i></button>`;
  $("#content").innerHTML = `
    <section class="g3 sc-row">${sc("Up-to-Date", "g", "✅")}${sc("Due Soon", "y", "⏰")}${sc("Overdue", "r", "🚨")}</section>
    <div class="card bar2">${search("Search pet or owner...")}${sel("vc", VACCINES, "All Vaccines")}<div class="chips">${chip("st", "", "All")}${chip("st", "Up-to-Date", "Up-to-Date")}${chip("st", "Due Soon", "Due Soon")}${chip("st", "Overdue", "Overdue")}</div><button class="btn" data-act="add" data-k="vaccinations">+ Add Vaccination</button></div>
    ${rows.length ? table(["Pet", "Vaccine", "Date Given", "Next Due", "Status", "Veterinarian", "Actions"], items.map((v) => `<tr>${cell("Pet", `<div class="who">${tile(petOfRec(v).species, v.i)}<span><b>${esc(v.pet)}</b><small>${esc(v.owner)}</small></span></div>`)}${cell("Vaccine", `<span class="vc">💉 ${esc(v.vaccine)}</span>`)}${cell("Date Given", fmtDate(v.given), "cc")}${cell("Next Due", fmtDate(v.next), "cc nd-" + v.status[0])}${cell("Status", badge(v.status, 1), "cc")}${cell("Veterinarian", esc(ovVet(v.vet)), "cc")}${cell("Actions", acts("vaccinations", v.i), "cc")}</tr>`).join(""), foot, "vx") : `<div class="card">${empty("No vaccination records found.")}</div>`}`;
}

/* ===== Adoption ===== */
const HEADS = ["c0", "c1", "c2", "c3", "c4", "c5"];
function pAdopt() {
  const q = state.q.toLowerCase();
  const tabs = [
    ["Available", "g"],
    ["Pending", "y"],
    ["Adopted", "b"],
  ];
  const rows = D.adoption
    .map((a, i) => ({ ...a, i }))
    .filter(
      (a) =>
        a.status === state.tab &&
        (!q ||
          [a.name, a.breed, a.species, a.reason]
            .join(" ")
            .toLowerCase()
            .includes(q)),
    );
  const primary = (a) =>
    a.status === "Available"
      ? `<button class="btn" data-act="adopt" data-k="adoption" data-i="${a.i}">Adopt</button>`
      : a.status === "Pending"
        ? `<button class="btn" data-act="finish" data-k="adoption" data-i="${a.i}">Complete</button>`
        : `<button class="btn" disabled>Adopted ✓</button>`;
  $("#content").innerHTML = `
    <div class="top">${search("Search pets for adoption...")}<button class="btn" data-act="add" data-k="adoption">+ Add Pet for Adoption</button></div>
    <div class="card tabs">${tabs.map(([t, c]) => `<button class="tab ${state.tab === t ? "on" : ""}" data-set="tab:${t}"><i class="dot-${c}"></i>${t}<em>${D.adoption.filter((a) => a.status === t).length}</em></button>`).join("")}</div>
    ${
      rows.length
        ? `<div class="pgrid">${rows
            .map(
              (
                a,
              ) => `<article class="pc"><div class="pc-h ${HEADS[a.i % 6]}"><span class="pc-em">${E[a.species] || "🐾"}</span><h3>${esc(a.name)}</h3><small>${esc(a.breed)}</small></div>
      <div class="pc-b"><div class="info"><div><small>ANIMAL TYPE</small><b>${E[a.species] || ""} ${esc(a.species)}</b></div><div><small>AGE</small><b>${esc(a.age)}</b></div><div><small>GENDER</small><b>${a.gender === "Male" ? "♂" : a.gender === "Female" ? "♀" : "⚧"} ${esc(a.gender)}</b></div><div><small>HEALTH</small><b>${esc(a.health)}</b></div></div>
      <div class="why">📌 ${esc(a.reason)}</div><div class="pc-a"><button class="btn out" data-act="view" data-k="adoption" data-i="${a.i}">View Details</button>${primary(a)}</div></div></article>`,
            )
            .join("")}</div>`
        : `<div class="card">${empty(`No ${state.tab.toLowerCase()} pets found.`)}</div>`
    }`;
}

/* ===== Users ===== */
function pUsers() {
  const q = state.q.toLowerCase();
  const rows = D.users
    .map((u, i) => ({ ...u, i }))
    .filter(
      (u) =>
        (!state.st || u.status === state.st) &&
        (!q ||
          [u.name, u.role, u.username, u.email]
            .join(" ")
            .toLowerCase()
            .includes(q)),
    );
  const { items, foot } = paginate(rows, 8);
  const ini = (n) => {
    const p = n.split(/\s+/);
    return (
      p.length > 1 ? p[0][0] + p[p.length - 1][0] : p[0].slice(0, 2)
    ).toUpperCase();
  };
  $("#content").innerHTML = `
    <div class="top"><p class="mu">${plural(D.users.length, "system account")}</p><button class="btn" data-act="add" data-k="users">+ Add User</button></div>
    <div class="card bar2">${search("Search users...")}${sel("st", ["Active", "Inactive"], "All Statuses")}</div>
    ${rows.length ? table(["Name", "Role", "Username", "Email", "Status", "Actions"], items.map((u) => `<tr>${cell("Name", `<div class="who"><span class="av sm">${ini(u.name)}</span><b>${esc(u.name)}</b></div>`)}${cell("Role", esc(u.role))}${cell("Username", esc(u.username))}${cell("Email", esc(u.email))}${cell("Status", badge(u.status))}${cell("Actions", acts("users", u.i))}</tr>`).join(""), foot) : `<div class="card">${empty("No users found.")}</div>`}`;
}

/* ===== Signed-in staff profile ===== */
async function pProfile() {
  $("#content").innerHTML = `<div class="card"><p class="mu">Loading your profile…</p></div>`;
  try {
    const r = await apiJson("api/profile.php");
    const u = r.user;
    $("#content").innerHTML = `<form class="card sform" id="staff-profile-form" enctype="multipart/form-data">
      <h3>My Profile</h3><p class="mu">Update your own account details and profile picture.</p>
      <div class="profile-photo-row"><div id="staff-profile-preview">${u.avatar_path ? `<img class="av lg" style="object-fit:cover" src="${esc(u.avatar_path)}" alt="Profile picture">` : `<span class="av lg">${esc((u.full_name||'U').split(/\\s+/).map(x=>x[0]).slice(0,2).join('').toUpperCase())}</span>`}</div>
      <div><label class="btn ghost">Change picture<input type="file" name="photo" id="staff-profile-photo" accept="image/jpeg,image/png,image/webp" hidden></label><button class="btn ghost" type="button" id="staff-profile-remove">Remove picture</button><small>JPG, PNG or WebP. Maximum 2 MB.</small></div></div>
      <label>Full Name<input name="full_name" required maxlength="120" value="${esc(u.full_name)}"></label>
      <label>Email<input name="email" type="email" required maxlength="190" value="${esc(u.email)}"></label>
      <label>Phone<input name="phone" maxlength="40" value="${esc(u.phone||'')}"></label>
      <label>Address<textarea name="address" maxlength="255" rows="3">${esc(u.address||'')}</textarea></label>
      <input type="hidden" name="remove_photo" value="0"><div class="acts"><span class="mu" id="staff-profile-msg"></span><button class="btn">Save Profile</button></div></form>`;
    const f=$("#staff-profile-form"),photo=$("#staff-profile-photo"),preview=$("#staff-profile-preview");
    photo.onchange=async()=>{const file=photo.files?.[0];if(!file)return;if(!["image/jpeg","image/png","image/webp"].includes(file.type)||file.size>2*1024*1024){photo.value="";$("#staff-profile-msg").textContent="Choose a JPG, PNG or WebP image up to 2 MB.";return;}const cropped=await cropPetImage(file);if(!cropped){photo.value="";return;}const dt=new DataTransfer();dt.items.add(cropped);photo.files=dt.files;preview.innerHTML=`<img class="av lg" style="object-fit:cover" src="${URL.createObjectURL(cropped)}" alt="Profile preview">`;f.elements.remove_photo.value="0";};
    $("#staff-profile-remove").onclick=()=>{photo.value="";f.elements.remove_photo.value="1";preview.textContent=(f.elements.full_name.value||"U").split(/\\s+/).map(x=>x[0]).slice(0,2).join("").toUpperCase();};
    f.onsubmit=async e=>{e.preventDefault();const b=e.submitter;b.disabled=true;$("#staff-profile-msg").textContent="Saving…";try{const saved=await apiJson("api/profile.php?action=save",new FormData(f));state.account={...state.account,name:saved.user.full_name,full_name:saved.user.full_name,email:saved.user.email,avatar_path:saved.user.avatar_path};showSaveSuccess("Profile updated.");buildShell();render();}catch(err){$("#staff-profile-msg").textContent=err.message;b.disabled=false;}};
  } catch(e) { $("#content").innerHTML=`<div class="card">${esc(e.message)}</div>`; }
}

/* ===== Settings ===== */
function pSettings() {
  const s = D.settings;
  $("#content").innerHTML =
    `<form class="card sform" id="sf"><h3>Clinic Information</h3><p class="mu">These details appear on reports and printed records.</p>
    <label>Clinic Name<input name="name" value="${esc(s.name)}" required></label><label>Contact Email<input name="email" type="email" value="${esc(s.email)}" required></label>
    <label>Phone<input name="phone" value="${esc(s.phone)}" required></label><label>Address<input name="address" value="${esc(s.address)}"></label>
    <div class="acts"><button class="btn">Save Changes</button></div></form>`;
  $("#sf").onsubmit = async (e) => {
    e.preventDefault();
    const btn = e.submitter || $("#sf .btn");
    btn.disabled = true;
    try {
      const res = await recordRequest("settings", "save", Object.fromEntries(new FormData(e.target)));
      Object.assign(D.settings, Object.fromEntries(new FormData(e.target)));
      showSaveSuccess(res.message || "Settings saved.");
    } catch (er) { alert(er.message); }
    btn.disabled = false;
  };
}

/* ===== Modals: add / edit / view ===== */
const F = (k, l, o = {}) => ({ k, l, ...o });
const petOpts = () => D.pets.map((p) => [String(p.id), p.owner ? `${p.name} — ${p.owner}` : p.name]);
const STAFF_ROLE_OPTS = ["Veterinarian", "Veterinary Staff"];
const FORM = {
  pets: () => [
    F("name", "Pet Name"),
    F("species", "Type of Animal", { ph: "e.g. Dog, Cat, Hamster, Parrot" }),
    F("breed", "Breed"),
    F("owner", "Owner Name"),
    F("ownerEmail", "Owner Email", { type: "email", opt: 1, ph: "Links the pet to the owner's portal account" }),
    F("phone", "Owner Phone", { opt: 1, ph: "e.g. 0917 123 4567" }),
    F("address", "Owner Address", { full: 1, ph: "Barangay, Olongapo City" }),
    F("age", "Age", { ph: "e.g. 3 yrs" }),
    F("gender", "Gender", { sel: ["Male", "Female", "Unknown"] }),
    F("notes", "Notes", { full: 1, opt: 1, ph: "Allergies, conditions, temperament (optional)" }),
  ],
  appointments: () => [
    F("petId", "Pet", { sel: petOpts() }),
    F("date", "Date", { type: "date" }),
    F("time", "Time", { sel: D.slots.map((t) => [t, fmtTime(t)]) }),
    F("purpose", "Purpose", { full: 1 }),
    F("status", "Status", { sel: ["Pending", "Confirmed", "In Progress"] }),
  ],
  vaccinations: () => [
    F("petId", "Pet", { sel: petOpts() }),
    F("vaccine", "Vaccine", { sel: VACCINES }),
    F("given", "Date Given", { type: "date" }),
    F("next", "Next Due", { type: "date" }),
  ],
  adoption: () => [
    F("name", "Name"),
    F("species", "Type of Animal", { ph: "e.g. Dog, Cat, Hamster, Parrot" }),
    F("breed", "Breed"),
    F("age", "Age", { ph: "e.g. 2 yrs" }),
    F("gender", "Gender", { sel: ["Male", "Female", "Unknown"] }),
    F("health", "Health", { sel: ["Vaccinated", "Not Vaccinated", "Under Treatment"] }),
    F("status", "Status", { sel: ["Available", "Pending", "Adopted"] }),
    F("reason", "Reason / Notes", { full: 1 }),
  ],
  users: (edit, rec) => [
    F("name", "Full Name"),
    F("role", "Role", { sel: rec && !STAFF_ROLE_OPTS.includes(rec.role) ? [rec.role] : STAFF_ROLE_OPTS }),
    F("username", "Username"),
    F("email", "Email", { type: "email" }),
    F("status", "Status", { sel: ["Active", "Inactive"] }),
    F("password", edit ? "New Password (leave blank to keep)" : "Password (min. 8 characters)", { type: "password", opt: edit ? 1 : 0, ph: edit ? "Leave blank to keep the current password" : "At least 8 characters" }),
  ],
};
const NOUN = {
  pets: "Pet",
  appointments: "Appointment",
  vaccinations: "Vaccination",
  adoption: "Adoption Pet",
  users: "User",
};
const ADD = {
  pets: "Add New Pet",
  appointments: "New Appointment",
  vaccinations: "Add Vaccination",
  adoption: "Add Pet for Adoption",
  users: "Add User",
};
const nextYear = () =>
  iso(new Date(TODAY.getFullYear() + 1, TODAY.getMonth(), TODAY.getDate()));
const DEF = {
  pets: () => ({ gender: "Male" }),
  appointments: () => ({
    petId: D.pets[0] ? String(D.pets[0].id) : "",
    date: state.sel || TODAY_ISO,
    time: D.slots[0] || "09:00",
    status: "Pending",
  }),
  vaccinations: () => ({ petId: state.vaxPet && D.pets.some((p) => String(p.id) === state.vaxPet) ? state.vaxPet : D.pets[0] ? String(D.pets[0].id) : "", given: TODAY_ISO, next: nextYear() }),
  adoption: () => ({ status: "Available", health: "Vaccinated", gender: "Male" }),
  users: () => ({ status: "Active", role: "Veterinarian" }),
};


function showSaveSuccess(message = 'Your changes have been saved.') {
  const overlay = document.createElement('div');
  overlay.className = 'save-success-overlay';
  overlay.setAttribute('role','status');
  overlay.setAttribute('aria-live','polite');
  overlay.innerHTML = `<div class="save-success-card"><span class="save-success-icon">✓</span><h3>Saved Successfully</h3><p>${esc(message)}</p></div>`;
  document.body.append(overlay);
  setTimeout(() => overlay.remove(), 2000);
}
function modal(html) {
  const m = document.createElement("div");
  m.className = "modal";
  m.innerHTML = `<div class="mbox" role="dialog" aria-modal="true">${html}</div>`;
  document.body.append(m);
  m.onclick = (e) => {
    if (e.target === m || e.target.closest("[data-x]")) m.remove();
  };
  return m;
}
async function cropPetImage(file) {
  return new Promise((resolve, reject) => {
    let objectUrl = '';
    let settled = false;
    const m = modal(`<div class="photo-cropper"><h2>Adjust Pet Picture</h2><p class="mu">Drag the picture to reposition it. Use the slider to zoom in or out.</p><canvas id="pet-crop-canvas" width="420" height="420" style="display:block;width:min(100%,420px);aspect-ratio:1;margin:12px auto;border-radius:14px;background:#eef2ef;touch-action:none;cursor:grab"></canvas><label>Zoom <input id="pet-crop-zoom" type="range" min="1" max="3" step="0.01" value="1"></label><div class="acts"><button type="button" class="btn ghost" id="pet-crop-cancel">Cancel</button><button type="button" class="btn" id="pet-crop-apply">Use Cropped Picture</button></div></div>`);
    // The cropper is opened from inside the Pet Owner Add/Edit Pet dialog.
    // Force it above that dialog so it cannot be hidden behind the form.
    m.style.setProperty('z-index', '20000', 'important');
    const canvas = $('#pet-crop-canvas',m), ctx = canvas && canvas.getContext('2d'), zoom = $('#pet-crop-zoom',m), apply = $('#pet-crop-apply',m);
    const img = new Image();
    let base=1, scale=1, x=0, y=0, dragging=false, lastX=0, lastY=0;
    const finish = value => {
      if (settled) return;
      settled = true;
      clearTimeout(loadTimeout);
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      m.remove();
      resolve(value);
    };
    const fail = error => {
      if (settled) return;
      settled = true;
      clearTimeout(loadTimeout);
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      m.remove();
      reject(error instanceof Error ? error : new Error('The picture could not be opened.'));
    };
    const loadTimeout = setTimeout(() => fail(new Error('The picture took too long to open. Please try another JPG, PNG or WebP image.')), 15000);
    $('#pet-crop-cancel',m).onclick = () => finish(null);
    m.addEventListener('click', e => { if (e.target === m) finish(null); }, true);
    if (!canvas || !ctx || !zoom || !apply) { fail(new Error('The picture editor could not be initialized. Please refresh and try again.')); return; }
    img.onload = () => {
      try {
        if (!img.naturalWidth || !img.naturalHeight) throw new Error('The selected picture is invalid or cannot be decoded.');
        base = Math.max(canvas.width/img.naturalWidth, canvas.height/img.naturalHeight);
        scale = base;
        const draw = () => {
          const w=img.naturalWidth*scale, h=img.naturalHeight*scale;
          const limX=Math.max(0,(w-canvas.width)/2), limY=Math.max(0,(h-canvas.height)/2);
          x=Math.max(-limX,Math.min(limX,x)); y=Math.max(-limY,Math.min(limY,y));
          ctx.clearRect(0,0,canvas.width,canvas.height);
          ctx.drawImage(img,(canvas.width-w)/2+x,(canvas.height-h)/2+y,w,h);
        };
        zoom.oninput = () => { const previous=scale; scale=base*Number(zoom.value); const ratio=scale/previous; x*=ratio; y*=ratio; draw(); };
        canvas.onpointerdown = e => { dragging=true; lastX=e.clientX; lastY=e.clientY; canvas.setPointerCapture(e.pointerId); canvas.style.cursor='grabbing'; };
        canvas.onpointermove = e => { if(!dragging)return; const r=canvas.getBoundingClientRect(); if(!r.width||!r.height)return; x+=(e.clientX-lastX)*canvas.width/r.width; y+=(e.clientY-lastY)*canvas.height/r.height; lastX=e.clientX; lastY=e.clientY; draw(); };
        canvas.onpointerup = canvas.onpointercancel = () => { dragging=false; canvas.style.cursor='grab'; };
        draw();
        apply.onclick = () => {
          apply.disabled = true;
          try {
            canvas.toBlob(blob => {
              if (!blob) { fail(new Error('The edited picture could not be created. Please try another image.')); return; }
              finish(new File([blob], 'pet-photo.jpg', {type:'image/jpeg',lastModified:Date.now()}));
            }, 'image/jpeg', 0.92);
          } catch (error) { fail(error); }
        };
      } catch (error) { fail(error); }
    };
    img.onerror = () => fail(new Error('This image format could not be opened. Please choose a JPG, PNG or WebP picture.'));
    try { objectUrl = URL.createObjectURL(file); img.src = objectUrl; }
    catch (error) { fail(error); }
  });
}

const optPair = (o) => (Array.isArray(o) ? o : [o, o]);
const fieldHtml = (f, v) =>
  `<label class="${f.full ? "full" : ""}">${f.l}${
    f.sel
      ? `<select name="${f.k}" ${f.opt ? "" : "required"}>${f.sel.map((o) => { const [val, lab] = optPair(o); return `<option value="${esc(val)}" ${String(val) === String(v ?? "") ? "selected" : ""}>${esc(lab)}</option>`; }).join("")}</select>`
      : `<input name="${f.k}" type="${f.type || "text"}" value="${f.type === "password" ? "" : esc(v)}" placeholder="${esc(f.ph || "")}" ${f.type === "password" ? 'autocomplete="new-password"' : ""} ${f.opt ? "" : "required"}>`
  }</label>`;

async function saveRecord(k, v, old, submittedFormData = null) {
  if (k === "users") return apiJson("api/users.php?action=save", { ...v, id: old ? old.id : 0 });
  if (k === "appointments") return apiJson("api/appointments.php?action=create", { pet_id: Number(v.petId), date: v.date, time: v.time, purpose: v.purpose, status: v.status });
  if (k === "pets") {
    // Preserve the actual File object in the original multipart form data.
    const fd = submittedFormData || new FormData();
    if (!submittedFormData) Object.entries(v).forEach(([key, val]) => fd.append(key, val));
    fd.set("id", String(old ? old.id : 0));
    return recordRequest(k, "save", fd);
  }
  return recordRequest(k, "save", { ...v, id: old ? old.id : 0 });
}
const reloadAfter = (k) => (k === "users" ? loadUsersFromDatabase() : loadRecordsFromDatabase());

function form(k, i) {
  const edit = i != null,
    r = edit ? D[k][i] : DEF[k](),
    fs = FORM[k](edit, edit ? r : null);
  if (!edit && (k === "appointments" || k === "vaccinations") && !D.pets.length) { toast("Register a pet first."); return; }
  const m = modal(
    `<form><h2>${edit ? "Edit " + NOUN[k] : ADD[k]}</h2>${!edit && (k === "appointments" || k === "vaccinations") ? `<p class="mu">${D.doctor ? `Veterinarian in charge: <b>${esc(drName(D.doctor.name))}</b> (assigned automatically)` : "No veterinarian yet. Set a user’s role to Veterinarian and they will be assigned automatically."}</p>` : ""}${k === "pets" ? `<div class="photo-pick"><div class="photo-prev" id="pp-prev">${petTile(r, 0)}</div><div><b>Pet picture</b><small class="mu" style="display:block">JPG, PNG or WebP · up to 2 MB (optional)</small><input type="file" name="photo" id="pp-file" accept="image/jpeg,image/png,image/webp" hidden><input type="hidden" name="remove_photo" value="0"><div class="photo-btns"><button type="button" class="btn ghost" id="pp-choose">📷 Choose picture</button>${edit && r.photo ? '<button type="button" class="btn ghost" id="pp-remove">Remove</button>' : ""}</div></div></div>` : ""}<div class="fg">${fs.map((f) => fieldHtml(f, r[f.k])).join("")}</div><div class="err" id="ferr"></div><div class="acts"><button type="button" class="btn ghost" data-x>Cancel</button><button class="btn">${edit ? "Save Changes" : "Save"}</button></div></form>`,
  );
  if (k === "pets") {
    const file = $("#pp-file", m), prev = $("#pp-prev", m), frm = $("form", m);
    $("#pp-choose", m).onclick = () => file.click();
    const rmv = $("#pp-remove", m);
    if (rmv) rmv.onclick = () => { file.value = ""; frm.elements.remove_photo.value = "1"; prev.innerHTML = tile(r.species, 0); rmv.remove(); };
    file.onchange = async () => {
      const f = file.files[0]; if (!f) return;
      if (!["image/jpeg", "image/png", "image/webp"].includes(f.type)) { file.value = ""; return ($("#ferr", m).textContent = "Only JPG, PNG or WebP pictures are allowed."); }
      if (f.size > 2 * 1024 * 1024) { file.value = ""; return ($("#ferr", m).textContent = "That picture is larger than 2 MB."); }
      $("#ferr", m).textContent = "Opening picture editor…";
      const cropped = await cropPetImage(f);
      if (!cropped) { file.value = ""; $("#ferr", m).textContent = "Picture editing cancelled."; return; }
      const transfer = new DataTransfer(); transfer.items.add(cropped); file.files = transfer.files;
      $("#ferr", m).textContent = ""; frm.elements.remove_photo.value = "0";
      prev.innerHTML = `<span class="tile has-img"><img src="${URL.createObjectURL(cropped)}" alt=""></span>`;
    };
  }
  $("form", m).onsubmit = async (e) => {
    e.preventDefault();
    const submittedFormData = new FormData(e.target),
      v = Object.fromEntries(submittedFormData),
      fail = (t) => ($("#ferr", m).textContent = t),
      btn = e.submitter || $(".acts .btn:not(.ghost)", m);
    for (const f of fs) v[f.k] = f.type === "password" ? String(v[f.k] ?? "") : String(v[f.k] ?? "").trim();
    if (fs.some((f) => !f.opt && !v[f.k])) return fail("Please fill in every required field.");
    if (k === "vaccinations" && v.next < v.given) return fail("Next due date must be after the date given.");
    if (k === "users" && v.password && v.password.length < 8) return fail("Password must be at least 8 characters.");
    btn.disabled = true;
    try {
      const res = await saveRecord(k, v, edit ? D[k][i] : null, submittedFormData);
      m.remove();
      await reloadAfter(k);
      showSaveSuccess(res.message || (edit ? "Changes saved." : "Record added."));
      refresh();
    } catch (er) { fail(er.message); btn.disabled = false; }
  };
  $("input,select", m)?.focus();
}

function detail(k, i) {
  const r = D[k][i],
    SHOW = { petId: (x) => x.pet },
    rows = FORM[k](true, r)
      .filter((f) => f.type !== "password")
      .map((f) => [
        f.l,
        f.type === "date" ? fmtDate(r[f.k]) : f.type === "time" ? fmtTime(r[f.k]) : SHOW[f.k] ? esc(SHOW[f.k](r)) : esc(r[f.k]) || "—",
      ]);
  if (k === "pets")
    rows.push(
      ["Registered", fmtDate(r.added)],
      ["Vax Status", badge(petVax(r))],
    );
  if (k === "appointments")
    rows.push(
      ["Owner", esc(petOfRec(r).owner || "—")],
      ["Address", esc(petOfRec(r).address || "—")],
    );
  if (k === "vaccinations")
    rows.push(
      ["Veterinarian", esc(ovVet(r.vet))],
      ["Owner", esc(petOfRec(r).owner || "—")],
      ["Status", badge(vaxStatus(r), 1)],
    );
  const vx = k === "pets" ? D.vaccinations.filter((v) => v.petId === r.id) : [],
    vxHtml =
      k === "pets"
        ? `<h3 class="vx-h">💉 Vaccination Records</h3>${
            vx.length
              ? `<div class="vxl">${vx.map((v) => `<div class="vxi"><div><b>${esc(v.vaccine)}</b><small>Given ${fmtDate(v.given)} · Next due ${fmtDate(v.next)} · ${esc(ovVet(v.vet))}</small></div>${badge(vaxStatus(v), 1)}</div>`).join("")}</div>`
              : '<p class="mu vx-none">No vaccination records yet.</p>'
          }`
        : "",
    addVax = k === "pets" ? `<button class="btn ghost" data-x data-act="add-vax" data-k="pets" data-i="${i}">💉 Add Vaccination</button>` : "";
  const m = modal(
    `<h2>${esc(r.name || r.pet)}</h2><dl class="dlist">${rows.map(([a, b]) => `<div><dt>${a}</dt><dd>${b}</dd></div>`).join("")}</dl>${vxHtml}<div class="acts">${addVax}<button class="btn red" data-x data-act="del" data-k="${k}" data-i="${i}">Delete</button><button class="btn ghost" data-x>Close</button><button class="btn" data-x data-act="edit" data-k="${k}" data-i="${i}">Edit</button></div>`,
  );
  m.addEventListener("click", (e) => {
    const b = e.target.closest("[data-act]");
    if (b) act(b.dataset.act, b.dataset.k, +b.dataset.i);
  });
}

async function remove(k, i) {
  const r = D[k][i],
    label = r.name || r.pet;
  const extra =
    k === "pets"
      ? " Their appointments and vaccination records will also be removed."
      : "";
  if (!confirm(`Delete "${label}"?${extra}`)) return;
  try {
    const res = k === "users" ? await apiJson("api/users.php?action=delete", { id: r.id }) : await recordRequest(k, "delete", { id: r.id });
    await reloadAfter(k);
    toast(res.message || "Record deleted.");
    refresh();
  } catch (e) { alert(e.message); }
}
async function adoptionStatus(i, status) {
  const r = D.adoption[i];
  let adopter = "";
  if (status === "Adopted") {
    adopter = prompt(`Who adopted ${r.name}? (optional)`);
    if (adopter === null) return;
  }
  try {
    await recordRequest("adoption", "status", { id: r.id, status, adopter_name: adopter.trim() });
    await loadRecordsFromDatabase();
    state.tab = status;
    toast(status === "Adopted" ? `${r.name} has been adopted! 🎉` : `${r.name} is now pending adoption.`);
    refresh();
  } catch (e) { alert(e.message); }
}

function act(a, k, i) {
  if (a === 'reload-appointments') { state.apptDbLoaded = false; state.apptLoadError = ''; render(); }
  else if (a === 'approve-appointment') { updateAppointmentStatus(i, 'Confirmed').catch(e => alert(e.message)); }
  else if (a === 'decline-appointment') { if (confirm('Reject this appointment request? The time slot will become available again.')) updateAppointmentStatus(i, 'Cancelled').catch(e => alert(e.message)); }
  else if (a === 'avail-add') { openClosureForm(); }
  else if (a === 'avail-reload') { state.avail = null; state.availError = ''; pAvail(); }
  else if (a === 'avail-del') { if (confirm('Remove this closure? Owners will be able to book that time again.')) availRequest('delete', { id: i }).then((r) => { state.avail = null; state.availError = ''; state.holi = null; pAvail(); toast(r.message); }).catch((e) => alert(e.message)); }
  else if (a === 'start-appointment') { updateAppointmentStatus(i, 'In Progress').catch(e => alert(e.message)); }
  else if (a === 'medical-report') { openMedicalReport(i); }
  else if (a === 'complete-appointment') { if (confirm('Mark this appointment as completed?')) updateAppointmentStatus(i, 'Completed').catch(e => alert(e.message)); }
  else if (a === 'reschedule-appointment') { openReschedule(i); }
  else if (a === 'owner-retry') { loadOwnerData().then(renderOwnerDashboard); }
  else if (a === 'reload-users') { state.usersLoading = false; render(); }
  else if (a === 'add-vax') { state.vaxPet = String(D.pets[i].id); ensureRecords().then(() => { form('vaccinations'); state.vaxPet = null; }); }
  else if (a === 'reload-records') { state.recordsLoaded = false; state.recordsError = ''; render(); }
  else if (a === "add") { (k === "appointments" || k === "vaccinations" ? ensureRecords() : Promise.resolve()).then(() => form(k)); }
  else if (a === "edit") form(k, i);
  else if (a === "view") detail(k, i);
  else if (a === "del") remove(k, i);
  else if (a === "adopt") { adoptionStatus(i, "Pending"); }
  else if (a === "finish") { adoptionStatus(i, "Adopted"); 
  } else if (a === "logout") {
    authRequest('logout').catch(() => {}).finally(() => {
      state.user = null; state.account = null; state.page = 'dashboard'; state.recordsLoaded = false; state.recordsError = ''; state.apptDbLoaded = false;
      Object.assign(D, { pets: [], appointments: [], vaccinations: [], adoption: [], users: [], doctor: null, slots: [] });
      login();
    });
  } else if (a === "bell") {
    const o = D.vaccinations.filter((v) => vaxStatus(v) === "Overdue").length,
      t = D.appointments.filter((x) => x.date === TODAY_ISO).length;
    toast(
      `${plural(o, "overdue vaccination")} · ${plural(t, "appointment")} today`,
    );
  }
}

/* ===== Pet Owner Portal: see owner.js (loaded before this file) ===== */
async function loadUsersFromDatabase() {
  if (state.account?.role !== 'Administrator') return;
  try {
    const response = await fetch('api/users.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || !result.success) throw new Error(result.message || 'Could not load users.');
    D.users = (result.users || []).map(u => ({
      id: u.id, name: u.full_name || u.name || '', role: u.role || '',
      username: u.username || '', email: u.email || '', status: u.status || 'Active'
    }));
    state.usersLoadError = '';
  } catch (error) {
    state.usersLoadError = error.message;
    D.users = [];
  }
}

/* ===== Event delegation (one set of listeners for the whole app) ===== */
app.addEventListener("click", (e) => {
  const t = e.target.closest(
    "[data-go],[data-act],[data-set],[data-pg],[data-day],[data-cal],[data-menu]",
  );
  if (!t) return;
  const d = t.dataset;
  if (d.menu) $(".app").classList.toggle("open");
  else if (d.go) go(d.go);
  else if (d.set) {
    const [k, ...v] = d.set.split(":");
    state[k] = v.join(":");
    state.pg = 1;
    refresh();
  } else if (d.pg) {
    state.pg = +d.pg;
    refresh();
  } else if (d.day) {
    state.sel = d.day;
    refresh();
  } else if (d.cal) moveMonth(+d.cal);
  else if (d.act) act(d.act, d.k, +d.i);
});
app.addEventListener("input", (e) => {
  const s = e.target.dataset.s;
  if (s) {
    state[s] = e.target.value;
    state.pg = 1;
    refresh();
  }
});
app.addEventListener("keydown", (e) => {
  if (e.target.id === "qs" && e.key === "Enter" && e.target.value.trim())
    go("pets", e.target.value.trim());
});

// Restore an existing PHP session; hide the startup screen only after the first view is ready.
(async function startApplication() {
  try {
    const r = await authRequest('me');
    state.user = r.user.username;
    state.account = r.user;
    const loadingMessage = document.getElementById('app-loading-message');
    if (loadingMessage) {
      const workspace = state.account.role === 'Administrator' ? 'administrator' : state.account.role === 'Veterinarian' ? 'veterinarian' : state.account.role === 'Pet Owner' ? 'pet owner' : 'clinic';
      loadingMessage.textContent = `Preparing your ${workspace} workspace`;
    }
    if (state.account.role === 'Pet Owner') await loadOwnerData();
    render();
  } catch (error) {
    state.user = null;
    login();
  } finally {
    if (window.appLoadingFailsafe) clearTimeout(window.appLoadingFailsafe);
    if (typeof window.finishAppLoading === 'function') window.finishAppLoading();
  }
})();
