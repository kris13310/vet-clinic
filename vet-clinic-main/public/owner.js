/* =====================================================================
   Pet Owner Portal  (owner.js)
   Loaded BEFORE app.js. It only defines functions/constants; app.js calls
   renderOwnerDashboard(), loadOwnerData() and reads ownerPageNames.
   Uses helpers from app.js at call time: $, $$, app, esc, toast, state, go,
   TODAY, TODAY_ISO, MONTHS, WEEKDAYS, fmtLong.
   ===================================================================== */

let ownerData = { doctor: null, profile: {}, pets: [], appointments: [], vaccinations: [], medical_reports: [], notifications: [], unread: 0, today: '', schedule: {} };
let ownerLoadError = '';
let ovPoll = null;
const ov = { uid: null, filter: 'all', nfilter: 'all', month: '', cal: { days: {}, loading: false, error: '' }, date: '', time: '', slots: [], slotsLoading: false, slotsOpen: true, dayState: 'open', dayLabel: '', petChoice: '', bellOpen: false, menuOpen: false };

const PHOTO_MAX = 2 * 1024 * 1024;
const PHOTO_OK = ['image/jpeg', 'image/png', 'image/webp'];

/* ---------- API ---------- */
async function ownerRequest(action = 'data', data = null, query = '') {
  const isForm = typeof FormData !== 'undefined' && data instanceof FormData;
  const response = await fetch('api/owner.php?action=' + encodeURIComponent(action) + query, {
    method: data ? 'POST' : 'GET', credentials: 'same-origin',
    headers: data && !isForm ? { 'Content-Type': 'application/json' } : {},
    body: data ? (isForm ? data : JSON.stringify(data)) : undefined
  });
  const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
  if (!response.ok || !result.success) throw new Error(result.message || 'Request failed.');
  return result;
}
const emptyOwnerData = () => ({ doctor: null, profile: {}, pets: [], appointments: [], vaccinations: [], medical_reports: [], notifications: [], unread: 0, today: '', schedule: {} });
async function loadOwnerData() {
  try {
    const r = await ownerRequest('data');
    ownerData = { doctor: r.doctor || null, profile: r.profile || {}, pets: r.pets || [], appointments: r.appointments || [], vaccinations: r.vaccinations || [], medical_reports: r.medical_reports || [], notifications: [], unread: r.unread || 0, today: r.today || '', schedule: r.schedule || {} };
    ownerLoadError = '';
    try { const n = await ownerRequest('notifications'); ownerData.notifications = n.notifications || []; ownerData.unread = n.unread || 0; } catch (e) { /* bell still works from the count */ }
  } catch (e) { ownerLoadError = e.message; ownerData = emptyOwnerData(); }
}

/* ---------- Formatting helpers ---------- */
const ownerPageNames = { 'owner-home': 'Overview', 'owner-appointments': 'Book Appointment', 'owner-pets': 'My Pets', 'owner-notifications': 'Notifications', 'owner-records': 'Medical Records', 'owner-profile': 'Personal Information', 'owner-settings': 'Settings' };
const OV_LABEL = { Pending: 'Pending', Confirmed: 'Approved', 'In Progress': 'In Progress', Completed: 'Completed', Cancelled: 'Rejected', Overdue: 'Overdue', Recorded: 'Recorded' };
const OV_HINT = { Pending: 'Waiting for clinic approval', Confirmed: 'Approved — see you then!', 'In Progress': 'Visit in progress', Completed: 'Visit completed', Cancelled: 'Rejected — please book another time' };
/* The clinic has one doctor: the account whose role is Veterinarian. Show the name as "Dr. Name". */
const drName = n => { n = String(n || '').trim(); return !n ? '' : /^dr\.?\s/i.test(n) ? n : 'Dr. ' + n; };
const ovVet = v => (!v || v === 'Not assigned' || v === 'Not recorded') ? (v || 'Not assigned') : drName(v);
const ownerToday = () => ownerData.today || TODAY_ISO;
const ownerDate = s => { if (!s) return 'Not scheduled'; const d = new Date(String(s).slice(0, 10) + 'T12:00:00'); return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); };
const ownerTime = s => { if (!s) return ''; const [h, m] = String(s).slice(0, 5).split(':').map(Number); return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`; };
/* Cancelled has two meanings: cancelled by the owner ("Cancelled") or declined by the clinic ("Rejected") */
const ovApptBadge = a => (a.status === 'Cancelled' && a.cancelled_by === 'Owner') ? '<span class="ov-status ov-s-cancelled">Cancelled</span>' : ownerBadge(a.status);
const ovApptHint = a => (a.status === 'Cancelled' && a.cancelled_by === 'Owner') ? 'You cancelled this appointment'
  : (a.status === 'Confirmed' && !a.can_cancel && String(a.appointment_date).slice(0, 10) >= ownerToday()) ? 'To change it, please contact the clinic' : (OV_HINT[a.status] || '');
const ownerBadge = s => `<span class="ov-status ov-s-${esc(String(s || 'pending').toLowerCase().replace(/[^a-z]+/g, '-'))}">${esc(OV_LABEL[s] || s || 'Pending')}</span>`;
const ovSpeciesKey = s => { const t = String(s || '').toLowerCase(); return /\b(dog|dogs|puppy|canine|aso)\b/.test(t) ? 'Dog' : /\b(cat|cats|kitten|feline|pusa)\b/.test(t) ? 'Cat' : /\b(rabbit|rabbits|bunny)\b/.test(t) ? 'Rabbit' : 'Other'; };
const ownerIcon = species => ({ Dog: '🐕', Cat: '🐈', Rabbit: '🐇', Other: '🐾' })[ovSpeciesKey(species)];
const ovInitials = n => (String(n || 'P').trim().split(/\s+/).map(x => x[0]).slice(0, 2).join('') || 'P').toUpperCase();
const ovSlug = s => String(s || '').toLowerCase().replace(/[^a-z]+/g, '-');
const ovAddDays = (isoDate, n) => { const d = new Date(isoDate + 'T12:00:00'); d.setDate(d.getDate() + n); return iso(d); };
const ovAgo = t => {
  const s = Math.max(0, (Date.now() - new Date(t).getTime()) / 1000);
  if (s < 60) return 'Just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' hr ago';
  if (s < 604800) { const d = Math.floor(s / 86400); return d + (d === 1 ? ' day ago' : ' days ago'); }
  return ownerDate(String(t).slice(0, 10));
};
const OV_PET_BG = { Dog: ['#fde68a', '#f59e0b'], Cat: ['#fbcfe8', '#ec4899'], Rabbit: ['#bae6fd', '#0ea5e9'], Other: ['#a7f3d0', '#10b981'] };
function defaultPetImage(species) {
  const [a, b] = OV_PET_BG[ovSpeciesKey(species)] || OV_PET_BG.Other;
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='${a}'/><stop offset='1' stop-color='${b}'/></linearGradient></defs><rect width='200' height='200' fill='url(#g)'/><g fill='#fff' fill-opacity='.93'><ellipse cx='62' cy='88' rx='13' ry='18' transform='rotate(-20 62 88)'/><ellipse cx='95' cy='66' rx='13' ry='18'/><ellipse cx='131' cy='70' rx='13' ry='18' transform='rotate(12 131 70)'/><ellipse cx='156' cy='100' rx='12' ry='16' transform='rotate(28 156 100)'/><path d='M104 106c-24 0-48 27-48 50 0 16 13 23 27 21 9-2 14-4 21-4s12 2 21 4c14 2 27-5 27-21 0-23-24-50-48-50z'/></g></svg>`;
  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}
const petSrc = p => (p && (p.photo_path || p.pet_photo)) ? esc(p.photo_path || p.pet_photo) : defaultPetImage(p && (p.species || p.pet_species));
const petImg = (p, cls = '') => `<img class="ov-pimg ${cls}" src="${petSrc(p)}" alt="${esc((p && (p.name || p.pet_name)) || 'Pet')}" loading="lazy" onerror="this.onerror=null;this.src='${defaultPetImage(p && (p.species || p.pet_species)).replace(/'/g, '%27')}'">`;
/* Vaccination status from the next due date: Overdue / Due soon (within 30 days) / Up to date */
const ovVaxStatus = v => {
  if (!v.next_due) return 'Recorded';
  const d = Math.round((new Date(String(v.next_due).slice(0, 10) + 'T12:00:00') - new Date(ownerToday() + 'T12:00:00')) / 864e5);
  return d < 0 ? 'Overdue' : d <= 30 ? 'Due soon' : 'Up to date';
};
const ovPetVax = p => {
  const list = ownerData.vaccinations.filter(v => v.pet_id == p.id).map(ovVaxStatus);
  return !list.length ? 'No records' : list.includes('Overdue') ? 'Overdue' : list.includes('Due soon') ? 'Due soon' : 'Up to date';
};
const ovIsActive = a => ['Pending', 'Confirmed', 'In Progress'].includes(a.status);

/* ---------- Modal ---------- */
function ovModal(html, wide = false) {
  const m = document.createElement('div');
  m.className = 'ov-modal';
  m.innerHTML = `<div class="ov-mbox ${wide ? 'wide' : ''}" role="dialog" aria-modal="true">${html}</div>`;
  document.body.append(m);
  document.body.classList.add('ov-lock');
  const close = () => { m.remove(); if (!$('.ov-modal')) document.body.classList.remove('ov-lock'); };
  m.close = close;
  m.addEventListener('mousedown', e => { if (e.target === m) m._down = true; });
  m.addEventListener('click', e => { if ((e.target === m && m._down) || e.target.closest('[data-close]')) close(); m._down = false; });
  return m;
}

/* =====================================================================
   Shell
   ===================================================================== */
function renderOwnerDashboard() {
  const page = ownerPageNames[state.page] ? state.page : 'owner-home'; state.page = page;
  const me = state.account || {};
  if (ov.uid !== me.id) { Object.assign(ov, { uid: me.id, filter: 'all', nfilter: 'all', month: '', date: '', time: '', slots: [], petChoice: '', bellOpen: false }); ov.cal = { days: {}, loading: false, error: '' }; }
  ov.menuOpen = false; ov.bellOpen = false;
  const displayName = me.name || me.full_name || me.username || 'Pet Owner';
  const upcoming = ownerData.appointments.filter(a => ovIsActive(a) && String(a.appointment_date).slice(0, 10) >= ownerToday());
  const nav = [['owner-home', '⌂', 'Overview', 0], ['owner-appointments', '＋', 'Book Appointment', upcoming.length], ['owner-pets', '🐾', 'My Pets', 0], ['owner-notifications', '🔔', 'Notifications', ownerData.unread], ['owner-records', '💉', 'Medical Records', 0], ['owner-profile', '👤', 'Personal Information', 0], ['owner-settings', '⚙️', 'Settings', 0]];
  const navHtml = nav.map(([id, ic, label, n]) => `<button class="ov-nav ${page === id ? 'active' : ''}" data-go="${id}"><span class="ov-nav-ic">${ic}</span><span class="ov-nav-t">${label}</span>${n ? `<em ${id === 'owner-notifications' ? 'id="ov-nav-notif"' : ''}>${n > 99 ? '99+' : n}</em>` : (id === 'owner-notifications' ? '<em id="ov-nav-notif" hidden></em>' : '')}</button>`).join('');
  const builders = { 'owner-home': ovPageHome, 'owner-appointments': ovPageAppointments, 'owner-pets': ovPagePets, 'owner-notifications': ovPageNotifications, 'owner-records': ovPageRecords, 'owner-profile': ovPageProfile, 'owner-settings': ovPageSettings };

  app.innerHTML = `<div class="ov-app${clinicThemeMode() === "dark" ? " theme-dark" : ""}">
    <aside class="ov-side" aria-label="Main navigation">
      <div class="ov-brand"><span class="ov-brand-mark">🐾</span><span><b>Olongapo Vet</b><small>Pet Owner Portal</small></span></div>
      <div class="ov-side-label">MY SPACE</div>
      <nav>${navHtml}</nav>
      <div class="ov-side-bottom">
        <div class="ov-help"><b>Need help?</b><p>Contact the veterinary office for help with your pet records.</p></div>
        <button class="ov-user" data-go="owner-profile"><span class="ov-avatar">${esc(ovInitials(displayName))}</span><span class="ov-user-t"><b>${esc(displayName)}</b><small>Pet Owner</small></span><span class="ov-chev">›</span></button>
      </div>
    </aside>
    <div class="ov-scrim" data-oact="menu"></div>
    <div class="ov-main">
      <header class="ov-top">
        <button class="ov-burger" data-oact="menu" aria-label="Open menu">☰</button>
        <div class="ov-top-t"><span class="ov-crumb">PET OWNER PORTAL / ${esc(ownerPageNames[page].toUpperCase())}</span><h1>${esc(ownerPageNames[page])}</h1></div>
        <div class="ov-top-r">
          <span class="ov-date">${esc(fmtLong(TODAY))}</span>
          <button class="ov-theme-toggle" type="button" data-theme-toggle="1" aria-label="Switch color theme">☾ Dark mode</button>
          <div class="ov-bellwrap">
            <button class="ov-bell" data-oact="bell" aria-label="Notifications" aria-haspopup="true">🔔<span class="ov-count" id="ov-bellcount" ${ownerData.unread ? '' : 'hidden'}>${ownerData.unread > 9 ? '9+' : ownerData.unread}</span></button>
            <div class="ov-bellpanel" id="ov-bellpanel" hidden></div>
          </div>
          <button class="ov-top-avatar" data-go="owner-profile" aria-label="Personal information">${esc(ovInitials(displayName))}</button>
        </div>
      </header>
      <div class="ov-content">${ownerLoadError ? `<div class="ov-alert">${esc(ownerLoadError)} <button type="button" data-act="owner-retry">Try again</button></div>` : ''}${builders[page]()}</div>
      <footer class="ov-footer">Made with care for every companion <span>♥</span> <small>Olongapo City Veterinary Office</small></footer>
    </div></div>`;

  applyClinicTheme();
  ovRenderBellPanel();
  if (page === 'owner-appointments') ovInitAppointments();
  if (page === 'owner-profile') ovInitProfile();
  if (page === 'owner-notifications') ovRenderNotifList();
  ovStartPolling();
}

/* =====================================================================
   Notifications (bell, dropdown, page)
   ===================================================================== */
const OV_NOTIF_ICON = { cancelled: ['🚫', 'bad'], closure: ['⚠️', 'warn'], approved: ['✅', 'ok'], rejected: ['❌', 'bad'], rescheduled: ['🔄', 'info'], completed: ['🏁', 'done'], request: ['📨', 'wait'] };
function ovNotifItem(n, compact) {
  const [ic, tone] = OV_NOTIF_ICON[n.type] || ['🔔', 'info'];
  return `<div class="ov-notif ${n.is_read ? '' : 'unread'} ${compact ? 'compact' : ''}" data-oact="notif-read" data-v="${n.id}" tabindex="0" role="button">
    <span class="ov-notif-ic ${tone}">${ic}</span>
    <div class="ov-notif-b"><b>${esc(n.title)}</b><p>${esc(n.message)}</p><small>${esc(ovAgo(n.created_at))}${n.is_read ? '' : ' · <i>New</i>'}</small></div>
    ${!compact && !n.is_read ? `<button type="button" class="ov-ghost sm" data-oact="notif-read" data-v="${n.id}">Mark as read</button>` : ''}
    ${n.is_read ? '' : '<span class="ov-dot" aria-label="Unread"></span>'}</div>`;
}
function ovRenderBellPanel() {
  const panel = $('#ov-bellpanel'); if (!panel) return;
  const list = ownerData.notifications.slice(0, 6);
  panel.innerHTML = `<div class="ov-bp-head"><b>Notifications</b>${ownerData.unread ? `<button type="button" class="ov-link" data-oact="notif-all">Mark all as read</button>` : ''}</div>
    <div class="ov-bp-list">${list.length ? list.map(n => ovNotifItem(n, true)).join('') : '<div class="ov-empty compact"><span>🔔</span><b>You\'re all caught up</b><p>Appointment updates will appear here.</p></div>'}</div>
    <button type="button" class="ov-bp-foot" data-go="owner-notifications">View all notifications</button>`;
  panel.hidden = !ov.bellOpen;
}
function ovRefreshNotifUi() {
  const c = $('#ov-bellcount'); if (c) { c.hidden = !ownerData.unread; c.textContent = ownerData.unread > 9 ? '9+' : ownerData.unread; }
  const nv = $('#ov-nav-notif'); if (nv) { nv.hidden = !ownerData.unread; nv.textContent = ownerData.unread > 99 ? '99+' : ownerData.unread; }
  ovRenderBellPanel();
  if (state.page === 'owner-notifications') ovRenderNotifList();
}
async function ovMarkRead(id) {
  const n = ownerData.notifications.find(x => x.id === id);
  if (n && n.is_read) return;
  if (n) { n.is_read = 1; ownerData.unread = Math.max(0, ownerData.unread - 1); ovRefreshNotifUi(); }
  try { const r = await ownerRequest('mark_read', { id }); ownerData.unread = r.unread; ovRefreshNotifUi(); }
  catch (e) { if (n) { n.is_read = 0; ownerData.unread++; ovRefreshNotifUi(); } toast(e.message); }
}
async function ovMarkAll() {
  try { const r = await ownerRequest('mark_read', { all: 1 }); ownerData.notifications.forEach(n => n.is_read = 1); ownerData.unread = r.unread; ovRefreshNotifUi(); toast('All notifications marked as read.'); }
  catch (e) { toast(e.message); }
}
function ovPageNotifications() {
  return `<div class="ov-intro"><div><span class="ov-eyebrow">STAY UPDATED</span><h2>Notifications</h2><p>Updates about your appointment requests: approved, rejected, rescheduled or completed.</p></div>
    <button class="ov-btn ghost" id="ov-markall" data-oact="notif-all" ${ownerData.unread ? '' : 'disabled'}>✓ Mark all as read</button></div>
    <section class="ov-card"><div class="ov-tabs" id="ov-ntabs"></div><div id="ov-nlist"></div></section>`;
}
function ovRenderNotifList() {
  const tabs = $('#ov-ntabs'), box = $('#ov-nlist'); if (!tabs || !box) return;
  const all = ownerData.notifications, unread = all.filter(n => !n.is_read);
  const mb = $('#ov-markall'); if (mb) mb.disabled = !unread.length;
  tabs.innerHTML = [['all', 'All', all.length], ['unread', 'Unread', unread.length]].map(([k, l, c]) => `<button class="ov-tab ${ov.nfilter === k ? 'on' : ''}" data-oact="nfilter" data-v="${k}">${l}<em>${c}</em></button>`).join('');
  const rows = ov.nfilter === 'unread' ? unread : all;
  box.innerHTML = rows.length ? `<div class="ov-nlist">${rows.map(n => ovNotifItem(n, false)).join('')}</div>` : `<div class="ov-empty"><span>🔔</span><b>${ov.nfilter === 'unread' ? 'No unread notifications' : 'No notifications yet'}</b><p>When the clinic approves, rejects, reschedules or completes an appointment, you will be notified here.</p></div>`;
}
function ovStartPolling() {
  if (ovPoll) return;
  ovPoll = setInterval(async () => {
    if (state.account?.role !== 'Pet Owner') { clearInterval(ovPoll); ovPoll = null; return; }
    if (document.hidden) return;
    try {
      const prev = ownerData.unread, prevIds = new Set(ownerData.notifications.map(n => n.id));
      const n = await ownerRequest('notifications');
      ownerData.notifications = n.notifications || []; ownerData.unread = n.unread || 0;
      const fresh = ownerData.notifications.filter(x => !prevIds.has(x.id));
      if (fresh.length || ownerData.unread > prev) {
        toast('🔔 ' + (fresh[0]?.title || 'You have a new notification'));
        const r = await ownerRequest('data');
        ownerData.appointments = r.appointments || []; ownerData.vaccinations = r.vaccinations || []; ownerData.pets = r.pets || []; ownerData.medical_reports = r.medical_reports || [];
        if (!document.querySelector('.ov-modal')) renderOwnerDashboard();
        if (state.page === 'owner-appointments') { ovRenderApptList(); ovRenderStats(); ovLoadMonth(true); }
      }
      ovRefreshNotifUi();
    } catch (e) { /* offline or session ended: try again next tick */ }
  }, 40000);
}

/* Pet Owner landing page: overview, quick actions and live account summaries. */
function ovPageHome() {
  const name = String((state.account || {}).name || (state.account || {}).full_name || 'there').trim().split(/\s+/)[0];
  const today = ownerToday();
  const upcoming = ownerData.appointments.filter(a => ovIsActive(a) && String(a.appointment_date).slice(0,10) >= today).sort((a,b) => (String(a.appointment_date)+a.appointment_time).localeCompare(String(b.appointment_date)+b.appointment_time));
  const next = upcoming[0], pets = ownerData.pets.slice(0,3), recent = ownerData.notifications.slice(0,3);
  let html = '<section class="ov-home-welcome"><div><span class="ov-eyebrow">YOUR PET CARE SPACE</span><h2>Welcome back, '+esc(name)+'!</h2><p>Everything about your pets and clinic visits, all in one place.</p><div class="ov-home-actions"><button class="ov-btn" data-go="owner-appointments">＋ Book an appointment</button><button class="ov-btn ghost" data-go="owner-pets">Manage my pets</button></div></div><div class="ov-home-art" aria-hidden="true">🐾　🐶　🐱</div></section>';
  html += '<section class="ov-home-stats"><article><span>🐾</span><div><b>'+ownerData.pets.length+'</b><small>My pets</small></div><button data-go="owner-pets">View →</button></article><article><span>📅</span><div><b>'+upcoming.length+'</b><small>Upcoming visits</small></div><button data-go="owner-appointments">View →</button></article><article><span>🔔</span><div><b>'+(ownerData.unread||0)+'</b><small>Unread updates</small></div><button data-go="owner-notifications">View →</button></article></section>';
  html += '<div class="ov-home-grid"><section class="ov-home-panel"><div class="ov-home-panel-head"><div><span class="ov-eyebrow">DON’T MISS IT</span><h3>Your next visit</h3></div></div>';
  if (next) { const pet = ownerData.pets.find(p => String(p.id) === String(next.pet_id)) || {}; html += '<div class="ov-home-visit">'+petImg(pet,'md')+'<div><b>'+esc(pet.name || next.pet_name || 'Your pet')+'</b><span>'+esc(ownerDate(next.appointment_date))+' · '+esc(ownerTime(next.appointment_time))+'</span><small>'+esc(next.purpose || 'Veterinary appointment')+'</small></div>'+ownerBadge(next.status)+'</div><button class="ov-home-link" data-go="owner-appointments">View appointment details →</button>'; }
  else html += '<div class="ov-home-empty"><span>🗓️</span><b>No upcoming visits</b><p>Your next appointment will appear here after you book a visit.</p><button class="ov-btn" data-go="owner-appointments">Book a visit</button></div>';
  html += '</section><section class="ov-home-panel"><div class="ov-home-panel-head"><div><span class="ov-eyebrow">YOUR COMPANIONS</span><h3>My pets</h3></div><button class="ov-home-link" data-go="owner-pets">See all →</button></div>';
  if (pets.length) { html += '<div class="ov-home-petlist">'+pets.map(p => '<button class="ov-home-pet" data-oact="pet-view" data-v="'+p.id+'">'+petImg(p,'sm')+'<span><b>'+esc(p.name)+'</b><small>'+esc([p.species,p.breed].filter(Boolean).join(' · ') || 'Pet profile')+'</small></span><i>›</i></button>').join('')+'</div>'; if(ownerData.pets.length>3) html += '<button class="ov-home-link" data-go="owner-pets">View all '+ownerData.pets.length+' pets →</button>'; }
  else html += '<div class="ov-home-empty"><span>🐾</span><b>Add your first pet</b><p>Create a profile to keep appointments and health records together.</p><button class="ov-btn" data-oact="pet-add">＋ Add a pet</button></div>';
  html += '</section><section class="ov-home-panel ov-home-updates"><div class="ov-home-panel-head"><div><span class="ov-eyebrow">LATEST FROM THE CLINIC</span><h3>Recent updates</h3></div><button class="ov-home-link" data-go="owner-notifications">All updates →</button></div>';
  if(recent.length) html += '<div class="ov-home-notifs">'+recent.map(n => '<div class="ov-home-notif"><span>'+(n.is_read?'○':'●')+'</span><div><b>'+esc(n.title)+'</b><p>'+esc(n.message)+'</p><small>'+esc(ovAgo(n.created_at))+'</small></div></div>').join('')+'</div>';
  else html += '<div class="ov-home-empty compact"><span>🔔</span><b>You’re all caught up</b><p>Clinic updates will show here.</p></div>';
  return html + '</section></div>';
}
/* =====================================================================
   Appointments page: availability booking + my appointments
   ===================================================================== */
function ovStatsHtml() {
  const a = ownerData.appointments, t = ownerToday();
  const upcoming = a.filter(x => ovIsActive(x) && String(x.appointment_date).slice(0, 10) >= t);
  const cards = [['📅', 'mint', upcoming.length, 'Upcoming visits'], ['⏳', 'amber', a.filter(x => x.status === 'Pending').length, 'Awaiting approval'], ['✅', 'blue', a.filter(x => x.status === 'Confirmed').length, 'Approved'], ['🏁', 'slate', a.filter(x => x.status === 'Completed').length, 'Completed']];
  return cards.map(([ic, tone, n, l]) => `<article class="ov-stat"><span class="ov-stat-ic ${tone}">${ic}</span><div><strong>${n}</strong><small>${l}</small></div></article>`).join('');
}
function ovRenderStats() { const s = $('#ov-stats'); if (s) s.innerHTML = ovStatsHtml(); }

function ovPetPicker() {
  const pets = ownerData.pets;
  if (!pets.some(p => String(p.id) === String(ov.petChoice)) && ov.petChoice !== 'new') ov.petChoice = pets.length ? String(pets[0].id) : 'new';
  const tiles = pets.map(p => `<label class="ov-pt ${String(p.id) === ov.petChoice ? 'sel' : ''}"><input type="radio" name="pet_id" value="${Number(p.id)}" ${String(p.id) === ov.petChoice ? 'checked' : ''}>${petImg(p)}<b>${esc(p.name)}</b><small>${esc([p.species, p.breed].filter(Boolean).join(' · '))}</small><i class="ov-check">✓</i></label>`).join('');
  return `<div class="ov-petpick">${tiles}<label class="ov-pt add ${ov.petChoice === 'new' ? 'sel' : ''}"><input type="radio" name="pet_id" value="new" ${ov.petChoice === 'new' ? 'checked' : ''}><span class="ov-pt-plus">＋</span><b>New pet</b><small>Register a pet</small><i class="ov-check">✓</i></label></div>
    <div class="ov-newpet" id="ov-newpet" ${ov.petChoice === 'new' ? '' : 'hidden'}><div class="ov-grid">
      <label>Pet name<input name="new_pet_name" maxlength="120" placeholder="Enter pet name" ${ov.petChoice === 'new' ? 'required' : ''}></label>
      <label>Type of animal<input name="new_pet_species" maxlength="60" placeholder="e.g. Dog, Cat, Hamster, Parrot" ${ov.petChoice === 'new' ? 'required' : ''}></label>
      <label>Breed (optional)<input name="new_pet_breed" maxlength="120" placeholder="Breed"></label>
      <label>Age (optional)<input name="new_pet_age" maxlength="40" placeholder="e.g. 2 years"></label></div>
      <p class="ov-hint">You can add a picture later from <b>My Pets</b>.</p></div>`;
}

function ovPageAppointments() {
  return `<div class="ov-intro"><div><span class="ov-eyebrow">BOOK A VISIT</span><h2>Request an Appointment</h2><p>Pick an available date and time. Your request stays Pending until the clinic approves it.</p></div></div>
  <section class="ov-stats" id="ov-stats">${ovStatsHtml()}</section>
  <form class="ov-card ov-book" id="ov-book" novalidate>
    <div class="ov-card-head"><div><h3>Appointment details</h3><p>Three quick steps: pet, date &amp; time, then the reason for your visit.</p></div><span class="ov-status ov-s-pending">Approval required</span></div>
    <section class="ov-step"><h4><span class="ov-num">1</span>Choose your pet</h4>${ovPetPicker()}</section>
    <section class="ov-step"><h4><span class="ov-num">2</span>Pick a date &amp; time</h4>
      <div class="ov-dt"><div class="ov-cal" id="ov-cal"></div><div class="ov-slots" id="ov-slots"></div></div></section>
    <section class="ov-step"><h4><span class="ov-num">3</span>Visit details</h4>
      <div class="ov-grid"><label class="full">Visit reason<input name="purpose" maxlength="255" required placeholder="e.g. General check-up, vaccination, skin problem"></label>
      <label class="full">Additional notes<textarea name="notes" rows="3" maxlength="5000" placeholder="Symptoms or information for the clinic (optional)"></textarea></label></div></section>
    <div class="ov-summary" id="ov-summary"></div>
    <div class="ov-actions"><span id="ov-book-msg" role="status" class="ov-msg"></span><button class="ov-btn" type="submit" id="ov-book-btn">＋ Request appointment</button></div>
  </form>
  <section class="ov-card" id="ov-appts" style="margin-top:28px"><div class="ov-card-head"><div><h3>My Appointments</h3><p>Date, time and approval status of every request</p></div></div><div class="ov-tabs" id="ov-atabs"></div><div id="ov-appt-list"></div></section>`;
}

function ovSelectedPet() {
  const v = $('#ov-book input[name="pet_id"]:checked')?.value;
  if (!v) return null;
  if (v === 'new') { const n = $('#ov-book [name="new_pet_name"]')?.value.trim() || 'New pet'; const sp = $('#ov-book [name="new_pet_species"]')?.value || 'Other'; return { name: n, species: sp, isNew: true }; }
  return ownerData.pets.find(p => String(p.id) === v) || null;
}
function ovRenderSummary() {
  const box = $('#ov-summary'), btn = $('#ov-book-btn'); if (!box) return;
  const pet = ovSelectedPet(), ready = !!(pet && ov.date && ov.time);
  box.innerHTML = `<div class="ov-sum-pet">${pet ? petImg(pet, 'sm') : ''}<div><small>Booking for</small><b>${esc(pet ? pet.name : '—')}</b>${ownerData.doctor ? `<small class="ov-with">🩺 with ${esc(drName(ownerData.doctor.name))}</small>` : ''}</div></div>
    <div><small>Date</small><b>${ov.date ? esc(ownerDate(ov.date)) : 'Not selected'}</b></div>
    <div><small>Time</small><b>${ov.time ? esc(ownerTime(ov.time)) : 'Not selected'}</b></div>`;
  if (btn) btn.disabled = !ready;
}

/* ----- calendar ----- */
function ovMonthBounds() { const t = ownerToday(); return { min: t.slice(0, 7), max: ovAddDays(t, Number(ownerData.schedule.window_days) || 90).slice(0, 7) }; }
async function ovLoadMonth(silent) {
  if (!ov.month) ov.month = ownerToday().slice(0, 7);
  if (!silent) { ov.cal.loading = true; ov.cal.error = ''; ovRenderCal(); }
  try {
    const r = await ownerRequest('availability', null, '&month=' + encodeURIComponent(ov.month));
    ov.cal.days = r.days || {}; ov.cal.loading = false; ov.cal.error = '';
    if (ov.date && ov.date.startsWith(ov.month) && ov.cal.days[ov.date]?.state !== 'available') { ov.date = ''; ov.time = ''; ov.slots = []; ovRenderSlots(); }
  } catch (e) { ov.cal.loading = false; ov.cal.error = e.message; }
  ovRenderCal(); ovRenderSummary();
}
function ovRenderCal() {
  const box = $('#ov-cal'); if (!box) return;
  const [y, m] = ov.month.split('-').map(Number), pad = new Date(y, m - 1, 1).getDay(), days = new Date(y, m, 0).getDate(), today = ownerToday(), b = ovMonthBounds();
  let cells = ''; for (let i = 0; i < pad; i++) cells += '<span class="ov-day blank"></span>';
  for (let d = 1; d <= days; d++) {
    const key = `${ov.month}-${String(d).padStart(2, '0')}`, info = ov.cal.days[key] || { state: ov.cal.loading ? 'loading' : 'closed', free: 0 }, ok = info.state === 'available';
    const sub = (ok ? `${info.free} open` : ({ full: 'Full', closed: 'Closed', holiday: 'Holiday', unavailable: 'Unavailable' })[info.state]) || '\u00a0';
    cells += `<button type="button" class="ov-day ${info.state} ${key === ov.date ? 'sel' : ''} ${key === today ? 'today' : ''}" ${ok ? `data-oact="cal-day" data-v="${key}"` : 'disabled'} title="${esc(info.label || '')}" aria-label="${esc(ownerDate(key))}: ${ok ? info.free + ' times open' : esc(info.label || info.state)}"><b>${d}</b><small>${sub}</small></button>`;
  }
  box.innerHTML = `<div class="ov-cal-head"><button type="button" class="ov-icon" data-oact="cal-prev" aria-label="Previous month" ${ov.month <= b.min ? 'disabled' : ''}>‹</button><h5>${MONTHS[m - 1]} ${y}</h5><button type="button" class="ov-icon" data-oact="cal-next" aria-label="Next month" ${ov.month >= b.max ? 'disabled' : ''}>›</button></div>
    <div class="ov-wd">${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(w => `<span>${w}</span>`).join('')}</div>
    <div class="ov-days ${ov.cal.loading ? 'loading' : ''}">${cells}</div>
    ${ov.cal.error ? `<p class="ov-err">${esc(ov.cal.error)}</p>` : ''}
    ${(() => { const n = Object.entries(ov.cal.days).filter(([k, v]) => k.startsWith(ov.month) && (v.state === 'holiday' || v.state === 'unavailable')).map(([k, v]) => `<li><b>${Number(k.slice(8))}</b> ${esc(v.label || '')}</li>`).join(''); return n ? `<ul class="ov-notes"><li class="t">Holidays &amp; closures this month</li>${n}</ul>` : ''; })()}
    <div class="ov-legend"><span><i class="a"></i>Available</span><span><i class="f"></i>Fully booked</span><span><i class="h"></i>Holiday / away</span><span><i class="c"></i>Closed / past</span></div>`;
}
async function ovPickDate(date) {
  ov.date = date; ov.time = ''; ov.slots = []; ov.dayState = 'open'; ov.dayLabel = ''; ov.slotsLoading = true;
  ovRenderCal(); ovRenderSlots(); ovRenderSummary();
  try { const r = await ownerRequest('availability', null, '&date=' + encodeURIComponent(date)); if (ov.date === date) { ov.slots = r.slots || []; ov.slotsOpen = r.open; ov.dayState = r.state; ov.dayLabel = r.label; } }
  catch (e) { if (ov.date === date) { ov.slots = []; toast(e.message); } }
  ov.slotsLoading = false; ovRenderSlots(); ovRenderSummary();
}
function ovRenderSlots() {
  const box = $('#ov-slots'); if (!box) return;
  if (!ov.date) { box.innerHTML = `<div class="ov-empty compact"><span>🕘</span><b>Select a date first</b><p>Available time slots will appear here.</p></div>`; return; }
  if (ov.slotsLoading) { box.innerHTML = `<h5>${esc(ownerDate(ov.date))}</h5><div class="ov-slotgrid">${'<span class="ov-slot skel"></span>'.repeat(8)}</div>`; return; }
  if (ov.dayState && ov.dayState !== 'open') {
    const why = { holiday: '🎌 Holiday', closed: '🔒 Clinic closed', unavailable: '🩺 Veterinarian unavailable' }[ov.dayState] || 'Not available';
    box.innerHTML = `<h5>${esc(ownerDate(ov.date))}</h5><div class="ov-closed"><b>${why}</b><p>${esc(ov.dayLabel || 'The clinic is not accepting appointments on this date.')}</p><p>Please choose another date from the calendar.</p></div>`; return;
  }
  const free = ov.slots.filter(s => s.available).length;
  const group = (label, list) => list.length ? `<p class="ov-slot-label">${label}</p><div class="ov-slotgrid">${list.map(s => `<button type="button" class="ov-slot ${s.available ? '' : s.reason} ${ov.time === s.time ? 'sel' : ''}" ${s.available ? `data-oact="slot" data-v="${s.time}"` : 'disabled'} aria-pressed="${ov.time === s.time}">${ownerTime(s.time)}${s.available ? '' : `<small>${({ booked: 'Booked', past: 'Passed', unavailable: 'Doctor away' })[s.reason] || 'Closed'}</small>`}</button>`).join('')}</div>` : '';
  box.innerHTML = `<div class="ov-slot-head"><h5>${esc(ownerDate(ov.date))}</h5><span class="ov-chip ${free ? 'ok' : 'bad'}">${free ? free + ' available' : 'No times left'}</span></div>
    ${group('Morning', ov.slots.filter(s => s.time < '12:00'))}${group('Afternoon', ov.slots.filter(s => s.time >= '12:00'))}
    ${ov.slots.length ? '' : '<p class="ov-hint">No time slots for this day.</p>'}<p class="ov-hint">Crossed-out times are already reserved by other pet owners.</p>`;
}

/* ----- cancel my own appointment ----- */
function ovCancelAppt(a) {
  const m = ovModal(`<div class="ov-mhead"><h2>Cancel this appointment?</h2><button type="button" class="ov-icon" data-close aria-label="Close">✕</button></div>
    <div class="ov-confirm">${petImg({ photo_path: a.pet_photo, species: a.pet_species, name: a.pet_name }, 'lg')}<p><b>${esc(a.pet_name)}</b> · ${esc(a.purpose)}<br>${esc(ownerDate(a.appointment_date))} at ${esc(ownerTime(a.appointment_time))}<br><br>The time will be released for other pet owners. You can book a new appointment any time.</p></div>
    <div class="ov-err" id="ov-cancel-err" role="alert"></div>
    <div class="ov-actions"><button class="ov-btn ghost" data-close>Keep appointment</button><button class="ov-btn danger solid" id="ov-cancel-go">Yes, cancel it</button></div>`);
  $('#ov-cancel-go', m).onclick = async e => {
    const b = e.currentTarget; b.disabled = true; b.textContent = 'Cancelling…';
    try { const r = await ownerRequest('cancel_appointment', { id: a.id }); m.close(); await loadOwnerData(); toast(r.message || 'Appointment cancelled.'); renderOwnerDashboard(); }
    catch (er) { $('#ov-cancel-err', m).textContent = er.message; b.disabled = false; b.textContent = 'Yes, cancel it'; }
  };
}

/* ----- my appointments list ----- */
function ovRenderApptList() {
  const tabs = $('#ov-atabs'), box = $('#ov-appt-list'); if (!tabs || !box) return;
  const all = ownerData.appointments, t = ownerToday();
  const FILTERS = [['all', 'All', () => true], ['Pending', 'Pending', a => a.status === 'Pending'], ['approved', 'Approved', a => ['Confirmed', 'In Progress'].includes(a.status)], ['Rejected', 'Rejected', a => a.status === 'Cancelled' && a.cancelled_by !== 'Owner'], ['Cancelled', 'Cancelled', a => a.status === 'Cancelled' && a.cancelled_by === 'Owner'], ['Completed', 'Completed', a => a.status === 'Completed']];
  tabs.innerHTML = FILTERS.map(([k, l, fn]) => `<button class="ov-tab ${ov.filter === k ? 'on' : ''}" data-oact="afilter" data-v="${k}">${l}<em>${all.filter(fn).length}</em></button>`).join('');
  const fn = FILTERS.find(f => f[0] === ov.filter)[2];
  const key = a => String(a.appointment_date).slice(0, 10) + String(a.appointment_time).slice(0, 5);
  const up = all.filter(a => fn(a) && ovIsActive(a) && String(a.appointment_date).slice(0, 10) >= t).sort((a, b) => key(a).localeCompare(key(b)));
  const rest = all.filter(a => fn(a) && !up.includes(a)).sort((a, b) => key(b).localeCompare(key(a)));
  const rows = [...up, ...rest];
  box.innerHTML = rows.length ? `<div class="ov-appts">${rows.map(a => {
    const d = new Date(String(a.appointment_date).slice(0, 10) + 'T12:00:00');
    return `<article class="ov-appt s-${ovSlug(a.status)}">
      ${petImg({ photo_path: a.pet_photo, species: a.pet_species, name: a.pet_name }, 'md')}
      <div class="ov-appt-main"><b>${esc(a.pet_name)}</b><span>${esc(a.purpose)}</span>
        <div class="ov-meta"><span>📅 ${esc(ownerDate(a.appointment_date))}</span><span>🕘 ${esc(ownerTime(a.appointment_time))}</span><span>🩺 ${esc(ovVet(a.veterinarian))}</span></div>
        ${a.notes ? `<small class="ov-note">“${esc(a.notes)}”</small>` : ''}</div>
      <div class="ov-datebox"><b>${d.getDate()}</b><span>${d.toLocaleDateString(undefined, { month: 'short' })}</span></div>
      <div class="ov-appt-st">${ovApptBadge(a)}<small>${esc(ovApptHint(a))}</small>${a.can_cancel ? `<button type="button" class="ov-btn danger sm" data-oact="appt-cancel" data-v="${a.id}">Cancel appointment</button>` : ''}</div></article>`;
  }).join('')}</div>` : `<div class="ov-empty"><span>📅</span><b>${all.length ? 'No appointments in this view' : 'No appointment requests yet'}</b><p>${all.length ? 'Try another status filter.' : 'Your submitted requests will appear here with their date, time and approval status.'}</p></div>`;
}

function ovInitAppointments() {
  const form = $('#ov-book'); if (!form) return;
  ov.time = ''; if (!ov.month) ov.month = ownerToday().slice(0, 7);
  ovRenderCal(); ovRenderSlots(); ovRenderSummary(); ovRenderApptList(); ovLoadMonth().then(() => { if (ov.date) ovPickDate(ov.date); });
  const syncPet = () => {
    const v = form.querySelector('input[name="pet_id"]:checked')?.value || ''; ov.petChoice = v;
    $$('.ov-pt', form).forEach(l => l.classList.toggle('sel', l.querySelector('input').checked));
    const isNew = v === 'new', box = $('#ov-newpet'); if (box) box.hidden = !isNew;
    const n = form.elements.namedItem('new_pet_name'); if (n) n.required = isNew;
    const sp = form.elements.namedItem('new_pet_species'); if (sp) sp.required = isNew;
    ovRenderSummary();
  };
  form.addEventListener('change', e => { if (e.target.name === 'pet_id') syncPet(); if (e.target.name === 'new_pet_species') ovRenderSummary(); });
  form.addEventListener('input', e => { if (e.target.name === 'new_pet_name' || e.target.name === 'new_pet_species') ovRenderSummary(); });
  form.onsubmit = async e => {
    e.preventDefault();
    const msg = $('#ov-book-msg'), btn = $('#ov-book-btn'); msg.className = 'ov-msg'; msg.textContent = '';
    if (!ov.date || !ov.time) { msg.className = 'ov-msg error'; msg.textContent = 'Please choose an available date and time.'; return; }
    if (!form.reportValidity()) return;
    const f = new FormData(form);
    const payload = { pet_id: f.get('pet_id'), purpose: String(f.get('purpose') || '').trim(), notes: String(f.get('notes') || '').trim(), appointment_date: ov.date, appointment_time: ov.time,
      new_pet_name: f.get('new_pet_name') || '', new_pet_species: String(f.get('new_pet_species') || '').trim(), new_pet_breed: f.get('new_pet_breed') || '', new_pet_age: f.get('new_pet_age') || '' };
    btn.disabled = true; msg.textContent = 'Sending request…';
    if (typeof window.showAppLoading === 'function') window.showAppLoading('Sending your appointment request…');
    try {
      const r = await ownerRequest('request_appointment', payload);
      ov.date = ''; ov.time = ''; ov.slots = []; ov.petChoice = '';
      await loadOwnerData(); toast(r.message || 'Appointment request sent.'); renderOwnerDashboard();
    } catch (err) {
      msg.className = 'ov-msg error'; msg.textContent = err.message; btn.disabled = false;
      if (/reserved|already|passed|too soon|closed|valid|past/i.test(err.message)) { const d = ov.date; ov.time = ''; await ovLoadMonth(true); if (ov.cal.days[d]?.state === 'available') await ovPickDate(d); else { ov.date = ''; ov.slots = []; ovRenderSlots(); ovRenderCal(); } ovRenderSummary(); }
    } finally { if (typeof window.finishAppLoading === 'function') window.finishAppLoading(); }
  };
}

/* =====================================================================
   My Pets: add / view / edit / delete + picture upload
   ===================================================================== */
function ovPagePets() {
  const pets = ownerData.pets;
  const cards = pets.map(p => {
    const next = ownerData.appointments.filter(a => a.pet_id == p.id && ovIsActive(a) && String(a.appointment_date).slice(0, 10) >= ownerToday()).sort((a, b) => (String(a.appointment_date) + a.appointment_time).localeCompare(String(b.appointment_date) + b.appointment_time))[0];
    return `<article class="ov-pet">
      <div class="ov-pet-ph">${petImg(p)}<span class="ov-pill">${ownerIcon(p.species)} ${esc(p.species)}</span></div>
      <div class="ov-pet-b"><h3>${esc(p.name)}</h3><p>${esc(p.breed || 'Breed not set')}</p>
        <div class="ov-chips"><span>🎂 ${esc(p.age || 'Age not set')}</span><span>${p.gender === 'Male' ? '♂' : p.gender === 'Female' ? '♀' : '⚧'} ${esc(p.gender || 'Unknown')}</span><span class="ov-vchip v-${ovSlug(ovPetVax(p))}">💉 ${esc(ovPetVax(p))}</span></div>
        ${ownerData.doctor ? `<div class="ov-pet-doc">🩺 Veterinarian in charge: <b>${esc(drName(ownerData.doctor.name))}</b></div>` : ''}
        <div class="ov-pet-next">${next ? `📅 Next visit: <b>${esc(ownerDate(next.appointment_date))}, ${esc(ownerTime(next.appointment_time))}</b>` : 'No upcoming visit'}</div>
        ${p.notes ? `<p class="ov-pet-notes">${esc(p.notes)}</p>` : ''}</div>
      <div class="ov-pet-a"><details class="ov-pet-menu"><summary aria-label="More pet actions" title="More actions">⋮</summary><div class="ov-pet-menu-list"><button data-oact="pet-view" data-v="${p.id}">View</button><button data-oact="pet-edit" data-v="${p.id}">Edit</button><button class="danger" data-oact="pet-del" data-v="${p.id}">Delete</button></div></details></div></article>`;
  }).join('');
  return `<div class="ov-intro"><div><span class="ov-eyebrow">YOUR COMPANIONS</span><h2>Pet Profiles</h2><p>Add, update and manage the pets connected to your account. ${esc(plural(pets.length, 'pet'))} registered.</p></div><button class="ov-btn" data-oact="pet-add">＋ Add pet</button></div>
    ${pets.length ? `<div class="ov-petgrid">${cards}</div>` : `<section class="ov-card"><div class="ov-empty"><span>🐾</span><b>No pets yet</b><p>Add your first pet to start booking appointments and keeping their records in one place.</p><button class="ov-btn" data-oact="pet-add">＋ Add your first pet</button></div></section>`}`;
}

function ovPetForm(pet) {
  const edit = !!pet, p = pet || {};
  const m = ovModal(`<form id="ov-petform" enctype="multipart/form-data" novalidate>
    <div class="ov-mhead"><h2>${edit ? 'Edit pet profile' : 'Add a pet'}</h2><button type="button" class="ov-icon" data-close aria-label="Close">✕</button></div>
    <div class="ov-photo"><div class="ov-photo-prev" id="ov-prev">${petImg(p, 'xl')}</div>
      <div class="ov-photo-c"><b>Pet picture</b><p>JPG, PNG or WebP · up to 2 MB</p>
        <input type="file" name="photo" id="ov-file" accept="image/jpeg,image/png,image/webp" hidden>
        <div class="ov-row"><button type="button" class="ov-btn ghost sm" id="ov-choose">📷 Choose picture</button>${edit && p.photo_path ? '<button type="button" class="ov-btn ghost sm" id="ov-removephoto">Remove</button>' : ''}</div>
        <input type="hidden" name="remove_photo" value="0"><small class="ov-err" id="ov-photo-err"></small></div></div>
    <div class="ov-grid">
      <label>Pet name<input name="name" required maxlength="120" value="${esc(p.name || '')}" placeholder="e.g. Bruno"></label>
      <label>Type of animal<input name="species" required maxlength="60" value="${esc(p.species || '')}" placeholder="e.g. Dog, Cat, Hamster, Parrot"></label>
      <label>Breed<input name="breed" maxlength="120" value="${esc(p.breed || '')}" placeholder="e.g. Labrador"></label>
      <label>Age<input name="age" maxlength="40" value="${esc(p.age || '')}" placeholder="e.g. 3 years"></label>
      <label>Gender<select name="gender">${['Male', 'Female', 'Unknown'].map(s => `<option ${(p.gender || 'Unknown') === s ? 'selected' : ''}>${s}</option>`).join('')}</select></label>
      <label class="full">Other information<textarea name="notes" rows="3" maxlength="2000" placeholder="Allergies, temperament, diet, medical conditions…">${esc(p.notes || '')}</textarea></label></div>
    <div class="ov-err" id="ov-pet-err" role="alert"></div>
    <div class="ov-actions"><button type="button" class="ov-btn ghost" data-close>Cancel</button><button class="ov-btn" type="submit" id="ov-pet-save">${edit ? 'Save changes' : 'Add pet'}</button></div></form>`, true);
  const file = $('#ov-file', m), prev = $('#ov-prev', m), err = $('#ov-photo-err', m), form = $('#ov-petform', m);
  const species = () => form.elements.namedItem('species').value;
  $('#ov-choose', m).onclick = () => file.click();
  const rm = $('#ov-removephoto', m);
  if (rm) rm.onclick = () => { file.value = ''; form.elements.namedItem('remove_photo').value = '1'; prev.innerHTML = petImg({ species: species() }, 'xl'); rm.remove(); err.textContent = ''; };
  file.onchange = async () => {
    err.textContent = ''; const f = file.files[0]; if (!f) return;
    if (!PHOTO_OK.includes(f.type)) { err.textContent = 'Only JPG, PNG or WebP pictures are allowed.'; file.value = ''; return; }
    if (f.size > PHOTO_MAX) { err.textContent = 'That picture is ' + (f.size / 1048576).toFixed(1) + ' MB. The maximum is 2 MB.'; file.value = ''; return; }
    err.textContent = 'Opening picture editor…';
    try {
      const cropped = await cropPetImage(f);
      if (!cropped) { file.value = ''; err.textContent = 'Picture editing cancelled.'; return; }
      if (typeof DataTransfer === 'undefined') throw new Error('Your browser cannot attach the edited picture. Please use an updated version of Chrome, Edge or Firefox.');
      const transfer = new DataTransfer();
      transfer.items.add(cropped);
      file.files = transfer.files;
      if (!file.files || !file.files.length) throw new Error('The edited picture could not be attached. Please choose it again.');
      err.textContent = '';
      form.elements.namedItem('remove_photo').value = '0';
      prev.innerHTML = `<img class="ov-pimg xl" alt="Selected picture" src="${URL.createObjectURL(cropped)}">`;
    } catch (error) {
      console.error('Pet Owner picture editor failed:', error);
      file.value = '';
      err.textContent = error && error.message ? error.message : 'Could not open the picture editor. Please try another picture or refresh the page.';
    }
  };
  form.elements.namedItem('species').oninput = () => { if (!file.files[0] && !(edit && p.photo_path && form.elements.namedItem('remove_photo').value !== '1')) prev.innerHTML = petImg({ species: species() }, 'xl'); };
  form.onsubmit = async e => {
    e.preventDefault(); const perr = $('#ov-pet-err', m), btn = $('#ov-pet-save', m); perr.textContent = '';
    if (!form.elements.namedItem('name').value.trim()) { perr.textContent = 'Please enter your pet\'s name.'; return; }
    if (!form.elements.namedItem('species').value.trim()) { perr.textContent = 'Please enter what type of animal your pet is.'; return; }
    if (err.textContent) { perr.textContent = 'Please fix the picture first.'; return; }
    const fd = new FormData(form); if (edit) fd.append('id', p.id);
    btn.disabled = true; btn.textContent = 'Saving…';
    if (typeof window.showAppLoading === 'function') window.showAppLoading(edit ? 'Updating your pet…' : 'Adding your pet…');
    try { const r = await ownerRequest('pet_save', fd); m.close(); await loadOwnerData(); showSaveSuccess(r.message || 'Pet saved.'); renderOwnerDashboard(); }
    catch (er) { perr.textContent = er.message; btn.disabled = false; btn.textContent = edit ? 'Save changes' : 'Add pet'; }
    finally { if (typeof window.finishAppLoading === 'function') window.finishAppLoading(); }
  };
  form.elements.namedItem('name').focus();
}
function ovPetView(p) {
  const appts = ownerData.appointments.filter(a => a.pet_id == p.id).slice(0, 4), vax = ownerData.vaccinations.filter(v => v.pet_id == p.id).sort((a, b) => String(b.date_given).localeCompare(String(a.date_given)));
  const m = ovModal(`<div class="ov-mhead"><h2>${esc(p.name)}</h2><button type="button" class="ov-icon" data-close aria-label="Close">✕</button></div>
    <div class="ov-view"><div class="ov-view-ph">${petImg(p, 'xl')}</div><div class="ov-view-b">
      <dl class="ov-dl"><div><dt>Species</dt><dd>${ownerIcon(p.species)} ${esc(p.species)}</dd></div><div><dt>Breed</dt><dd>${esc(p.breed || '—')}</dd></div><div><dt>Age</dt><dd>${esc(p.age || '—')}</dd></div><div><dt>Gender</dt><dd>${esc(p.gender || 'Unknown')}</dd></div><div class="full"><dt>Other information</dt><dd>${esc(p.notes || '—')}</dd></div>${ownerData.doctor ? `<div class="full"><dt>Veterinarian in charge</dt><dd>🩺 ${esc(drName(ownerData.doctor.name))}</dd></div>` : ''}<div><dt>Registered</dt><dd>${esc(ownerDate(p.created_at))}</dd></div><div><dt>Vaccinations</dt><dd>${vax.length ? plural(vax.length, 'record') : 'None recorded'}</dd></div></dl>
      <h4 class="ov-sub">💉 Vaccination records</h4>${vax.length ? vax.map(v => `<div class="ov-vx"><div><b>${esc(v.vaccine)}</b><small>Given ${esc(ownerDate(v.date_given))} · Next due ${esc(ownerDate(v.next_due))} · ${esc(ovVet(v.veterinarian))}</small></div>${ownerBadge(ovVaxStatus(v))}</div>`).join('') : '<p class="ov-hint">No vaccinations recorded yet. The clinic will add them after your visit.</p>'}
      <h4 class="ov-sub" style="margin-top:22px">Recent appointments</h4>${appts.length ? appts.map(a => `<div class="ov-mini"><span>${esc(ownerDate(a.appointment_date))} · ${esc(ownerTime(a.appointment_time))}</span>${ownerBadge(a.status)}</div>`).join('') : '<p class="ov-hint">No appointments yet.</p>'}</div></div>
    <div class="ov-actions"><button class="ov-btn ghost" data-close>Close</button><button class="ov-btn ghost" data-oact="pet-edit" data-v="${p.id}" data-close>✏️ Edit</button><button class="ov-btn" data-oact="pet-book" data-v="${p.id}" data-close>📅 Book appointment</button></div>`, true);
  return m;
}
function ovPetDelete(p) {
  const m = ovModal(`<div class="ov-mhead"><h2>Delete ${esc(p.name)}?</h2><button type="button" class="ov-icon" data-close aria-label="Close">✕</button></div>
    <div class="ov-confirm">${petImg(p, 'lg')}<p>This permanently removes <b>${esc(p.name)}</b>'s profile and picture, and any appointment requests that are still waiting for approval. This cannot be undone.</p></div>
    <div class="ov-err" id="ov-del-err" role="alert"></div>
    <div class="ov-actions"><button class="ov-btn ghost" data-close>Keep pet</button><button class="ov-btn danger solid" id="ov-del-go">Yes, delete</button></div>`);
  $('#ov-del-go', m).onclick = async e => {
    const b = e.currentTarget; b.disabled = true; b.textContent = 'Deleting…';
    if (typeof window.showAppLoading === 'function') window.showAppLoading('Deleting your pet…');
    try { const r = await ownerRequest('pet_delete', { id: p.id }); m.close(); await loadOwnerData(); toast(r.message || 'Pet deleted.'); renderOwnerDashboard(); }
    catch (er) { $('#ov-del-err', m).textContent = er.message; b.disabled = false; b.textContent = 'Yes, delete'; }
    finally { if (typeof window.finishAppLoading === 'function') window.finishAppLoading(); }
  };
}

/* =====================================================================
   Medical records, personal information, settings
   ===================================================================== */
function ovPageRecords() {
  const v = ownerData.vaccinations, reports = ownerData.medical_reports || [];
  return `<div class="ov-intro"><div><span class="ov-eyebrow">HEALTH HISTORY</span><h2>Medical Records</h2><p>View veterinarian reports, vaccination history and follow-up instructions for your pets.</p></div></div>
  <section class="ov-card"><h3>Veterinarian Medical Reports</h3>${reports.length ? reports.map(r => `<article class="ov-record-report"><div class="ov-who">${petImg({photo_path:r.pet_photo,species:r.pet_species,name:r.pet_name},'xs')}<div><b>${esc(r.pet_name)}</b><small>${esc(ownerDate(r.appointment_date))} · ${esc(r.veterinarian || 'Veterinarian')}</small></div></div><div class="ov-grid"><div><b>Diagnosis</b><p>${esc(r.diagnosis)}</p></div><div><b>Symptoms</b><p>${esc(r.symptoms)}</p></div><div><b>Observations</b><p>${esc(r.observations || '—')}</p></div><div><b>Treatment</b><p>${esc(r.treatment)}</p></div><div><b>Medication</b><p>${esc(r.medication || '—')}</p></div><div><b>Recommendations</b><p>${esc(r.recommendations || '—')}</p></div><div><b>Follow-up date</b><p>${esc(r.follow_up_date ? ownerDate(r.follow_up_date) : 'Not scheduled')}</p></div></div></article>`).join('') : `<div class="ov-empty"><span>🩺</span><b>No veterinarian reports yet</b><p>Reports submitted after your pet's consultation will appear here.</p></div>`}</section>
  <section class="ov-card"><h3>Vaccination History</h3>${v.length ? `<div class="ov-tablewrap"><table class="ov-table"><thead><tr><th>Pet</th><th>Vaccine</th><th>Date given</th><th>Next due</th><th>Veterinarian</th><th>Notes</th></tr></thead><tbody>${v.map(x => `<tr><td><div class="ov-who">${petImg({ photo_path: x.pet_photo, species: x.pet_species, name: x.pet_name }, 'xs')}<b>${esc(x.pet_name)}</b></div></td><td>💉 ${esc(x.vaccine)}</td><td>${esc(ownerDate(x.date_given))}</td><td>${esc(ownerDate(x.next_due))} ${x.next_due && String(x.next_due) < ownerToday() ? ownerBadge('Overdue') : ''}</td><td>${esc(ovVet(x.veterinarian))}</td><td>${esc(x.notes || '—')}</td></tr>`).join('')}</tbody></table></div>` : `<div class="ov-empty"><span>💚</span><b>No vaccination records yet</b><p>Vaccination records recorded by the clinic will appear here.</p></div>`}</section>`;
}
function ovPageProfile() {
  const me = state.account || {}, p = ownerData.profile || {}, name = p.name || me.name || '';
  const avatar = p.avatar_path ? `<img class="ov-pimg xl" src="${esc(p.avatar_path)}" alt="Profile picture">` : `<div class="ov-avatar lg">${esc(ovInitials(name))}</div>`;
  return `<div class="ov-intro"><div><span class="ov-eyebrow">ACCOUNT DETAILS</span><h2>Personal Information</h2><p>Keep your contact information current for clinic updates.</p></div></div>
  <form class="ov-card ov-narrow" id="owner-profile-form" enctype="multipart/form-data"><div class="ov-profile-head"><div id="owner-avatar-preview">${avatar}</div><div><h3>${esc(name || 'Pet Owner')}</h3><p>Pet Owner account</p><label class="ov-btn ghost sm">Change profile picture<input type="file" name="photo" id="owner-profile-photo" accept="image/jpeg,image/png,image/webp" hidden></label><button class="ov-btn ghost sm" type="button" id="owner-profile-remove">Remove picture</button></div></div>
    <div class="ov-grid"><label>Full name<input name="name" required maxlength="120" value="${esc(name)}"></label><label>Email address<input name="email" type="email" required value="${esc(p.email || me.email || '')}"></label>
    <label>Phone number<input name="phone" maxlength="40" value="${esc(p.phone || '')}" placeholder="e.g. 09XX XXX XXXX"></label><label>Home address<textarea name="address" rows="3" maxlength="255" placeholder="Barangay, city">${esc(p.address || '')}</textarea></label></div>
    <input type="hidden" name="remove_photo" value="0"><div class="ov-actions"><span id="owner-profile-msg" class="ov-msg" role="status"></span><button class="ov-btn" type="submit">Save personal information</button></div></form>`;
}
function ovInitProfile() {
  const form = $('#owner-profile-form'); if (!form) return;
  const photo = $('#owner-profile-photo'), preview = $('#owner-avatar-preview'), remove = $('#owner-profile-remove');
  photo.onchange = async () => {
    const file = photo.files?.[0]; if (!file) return;
    const msg = $('#owner-profile-msg');
    msg.textContent = '';
    if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      photo.value = ''; msg.textContent = 'Choose a JPG, PNG or WebP image up to 2 MB.'; msg.className = 'ov-msg error'; return;
    }
    msg.textContent = 'Opening picture editor…'; msg.className = 'ov-msg';
    try {
      const cropped = await cropPetImage(file);
      if (!cropped) { photo.value = ''; msg.textContent = 'Picture editing cancelled.'; return; }
      if (typeof DataTransfer === 'undefined') throw new Error('Your browser cannot attach the edited picture. Please update your browser.');
      const transfer = new DataTransfer(); transfer.items.add(cropped); photo.files = transfer.files;
      if (!photo.files || !photo.files.length) throw new Error('The edited picture could not be attached. Please choose it again.');
      preview.innerHTML = `<img class="ov-pimg xl" alt="Profile picture preview" src="${URL.createObjectURL(cropped)}">`;
      form.elements.remove_photo.value = '0'; msg.textContent = '';
    } catch (error) {
      photo.value = ''; msg.textContent = error.message || 'Could not open the picture editor.'; msg.className = 'ov-msg error';
    }
  };
  remove.onclick=()=>{photo.value='';form.elements.remove_photo.value='1';preview.innerHTML=`<div class="ov-avatar lg">${esc(ovInitials(form.elements.name.value))}</div>`;};
  form.onsubmit = async e => {
    e.preventDefault(); const msg = $('#owner-profile-msg'); msg.textContent = 'Saving…'; msg.className = 'ov-msg';
    if (typeof window.showAppLoading === 'function') window.showAppLoading('Saving your profile…');
    try {
      const r = await ownerRequest('profile', new FormData(form));
      state.account = { ...state.account, name: r.user.name, full_name: r.user.name, email: r.user.email, avatar_path: r.user.avatar_path || null };
      await loadOwnerData(); showSaveSuccess('Personal information saved.'); renderOwnerDashboard();
    } catch (err) { msg.textContent = err.message; msg.className = 'ov-msg error'; }
    finally { if (typeof window.finishAppLoading === 'function') window.finishAppLoading(); }
  };
}
function ovPageSettings() {
  const me = state.account || {};
  const profile = ownerData.profile || {};
  const name = profile.name || me.name || me.full_name || 'Pet Owner';
  const email = profile.email || me.email || 'No email saved';
  const username = me.username || 'Not available';
  return `
    <div class="ov-settings-page">
      <div class="ov-intro"><div><span class="ov-eyebrow">ACCOUNT</span><h2>Settings</h2><p>Manage your personal details and keep your account secure.</p></div></div>
      <section class="ov-settings-account">
        <div class="ov-settings-avatar">${profile.avatar_path ? `<img src="${esc(profile.avatar_path)}" alt="Profile picture">` : esc(ovInitials(name))}</div>
        <div class="ov-settings-identity"><span class="ov-eyebrow">SIGNED IN AS</span><h3>${esc(name)}</h3><p>${esc(email)}</p><small>@${esc(username)} · Pet Owner</small></div>
        <button class="ov-btn ghost sm" data-go="owner-profile">Edit profile</button>
      </section>
      <div class="ov-settings-section"><span class="ov-eyebrow">APPEARANCE</span><h3>Display theme</h3><p class="ov-settings-description">Choose light or dark mode for your Pet Owner portal. Your preference is saved on this device.</p><button class="ov-theme-toggle" type="button" data-theme-toggle="1" aria-label="Switch color theme">${clinicThemeMode() === "dark" ? "☀ Light mode" : "☾ Dark mode"}</button></div>
      <div class="ov-settings-section"><span class="ov-eyebrow">ACCOUNT PREFERENCES</span><h3>Account and security</h3>
        <button class="ov-settings-row" data-go="owner-profile"><span class="ov-settings-icon">👤</span><span class="ov-settings-copy"><b>Personal information</b><small>Update your name, email, phone number and address</small></span><span class="ov-settings-arrow">›</span></button>
        <button class="ov-settings-row" data-oact="password"><span class="ov-settings-icon">🔐</span><span class="ov-settings-copy"><b>Change password</b><small>Update the password you use to sign in</small></span><span class="ov-settings-arrow">›</span></button>
      </div>
      <div class="ov-settings-section"><span class="ov-eyebrow">UPDATES</span><h3>Notifications</h3>
        <button class="ov-settings-row" data-go="owner-notifications"><span class="ov-settings-icon">🔔</span><span class="ov-settings-copy"><b>Appointment notifications</b><small>View approval, rescheduling and appointment updates</small></span><span class="ov-settings-arrow">›</span></button>
      </div>
      <div class="ov-settings-signout"><div><b>Sign out</b><small>End your current session on this device.</small></div><button class="ov-btn danger sm" data-act="logout">Log out</button></div>
    </div>`;
}
function ovPasswordForm() {
  const modal = ovModal(`
    <div class="ov-mhead"><div><span class="ov-eyebrow">ACCOUNT SECURITY</span><h2>Change password</h2><p>Choose a new password for your account.</p></div><button class="ov-x" data-close aria-label="Close">×</button></div>
    <form id="ov-password-form" class="ov-password-form">
      <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
      <label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="8" required><small>Use at least 8 characters.</small></label>
      <label>Confirm new password<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
      <p class="ov-msg" id="ov-password-msg" aria-live="polite"></p>
      <div class="ov-actions"><button type="button" class="ov-btn ghost" data-close>Cancel</button><button type="submit" class="ov-btn">Update password</button></div>
    </form>`, true);
  const form = modal.querySelector('#ov-password-form');
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const msg = form.querySelector('#ov-password-msg');
    const submit = form.querySelector('[type="submit"]');
    const data = Object.fromEntries(new FormData(form).entries());
    if (data.new_password !== data.confirm_password) { msg.textContent = 'The new passwords do not match.'; msg.className = 'ov-msg error'; return; }
    submit.disabled = true; submit.textContent = 'Updating…'; msg.textContent = '';
    try {
      const result = await ownerRequest('password', data);
      msg.textContent = result.message || 'Password updated successfully.';
      msg.className = 'ov-msg success';
      form.reset();
      setTimeout(() => modal.close(), 1000);
    } catch (err) { msg.textContent = err.message; msg.className = 'ov-msg error'; }
    finally { submit.disabled = false; submit.textContent = 'Update password'; }
  });
}

/* =====================================================================
   Event delegation for the owner portal (data-oact attributes)
   ===================================================================== */
document.addEventListener('click', e => {
  if (!(state && state.account && state.account.role === 'Pet Owner')) return;
  if (ov.bellOpen && !e.target.closest('.ov-bellwrap')) { ov.bellOpen = false; const p = $('#ov-bellpanel'); if (p) p.hidden = true; }
  const t = e.target.closest('[data-oact]'); if (!t) return;
  const a = t.dataset.oact, v = t.dataset.v;
  const pet = () => ownerData.pets.find(p => String(p.id) === String(v));
  if (a === 'menu') { ov.menuOpen = !ov.menuOpen; $('.ov-app')?.classList.toggle('open', ov.menuOpen); }
  else if (a === 'bell') { ov.bellOpen = !ov.bellOpen; const p = $('#ov-bellpanel'); if (p) p.hidden = !ov.bellOpen; }
  else if (a === 'notif-read') { e.stopPropagation(); ovMarkRead(Number(v)); }
  else if (a === 'notif-all') ovMarkAll();
  else if (a === 'nfilter') { ov.nfilter = v; ovRenderNotifList(); }
  else if (a === 'afilter') { ov.filter = v; ovRenderApptList(); }
  else if (a === 'cal-prev' || a === 'cal-next') { const [y, m] = ov.month.split('-').map(Number), d = new Date(y, m - 1 + (a === 'cal-next' ? 1 : -1), 1); ov.month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; ovLoadMonth(); }
  else if (a === 'cal-day') ovPickDate(v);
  else if (a === 'slot') { ov.time = v; ovRenderSlots(); ovRenderSummary(); }
  else if (a === 'password') ovPasswordForm();
  else if (a === 'pet-add') ovPetForm();
  else if (a === 'pet-view') { const p = pet(); if (p) ovPetView(p); }
  else if (a === 'pet-edit') { const p = pet(); if (p) ovPetForm(p); }
  else if (a === 'pet-del') { const p = pet(); if (p) ovPetDelete(p); }
  else if (a === 'appt-cancel') { const x = ownerData.appointments.find(q => String(q.id) === String(v)); if (x) ovCancelAppt(x); }
  else if (a === 'pet-book') { ov.petChoice = String(v); go('owner-appointments'); }
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const m = [...document.querySelectorAll('.ov-modal')].pop(); if (m) { m.close(); return; }
    if (ov.bellOpen) { ov.bellOpen = false; const p = $('#ov-bellpanel'); if (p) p.hidden = true; }
  }
  if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.ov-notif[data-oact]')) { e.preventDefault(); ovMarkRead(Number(e.target.dataset.v)); }
});
