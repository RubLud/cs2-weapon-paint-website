import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { communityRequest, useCommunityResource } from '../lib/community'
import '../styles/Social.css'
const endpoint = (action, fields = {}) => 'social.php?' + new URLSearchParams({ action, ...fields })
const time = value => new Date(value.replace(' ', 'T') + 'Z').toLocaleString('pl-PL')
const labels = { invite:'Wyślij zaproszenie',accept:'Akceptuj',reject:'Odrzuć',cancel:'Anuluj zaproszenie',remove:'Usuń znajomego',block:'Zablokuj',unblock:'Odblokuj' }
const states = { none:'Brak relacji',sent:'Zaproszenie wysłane',received:'Otrzymane zaproszenie',friends:'Znajomi',blocked:'Interakcje zablokowane' }
function Resource({ resource, children }) {
  if (resource.loading) return <p role="status">Wczytywanie…</p>
  if (resource.error) return <p role="alert">{resource.error.status === 404 ? 'Ta część społeczności jest niedostępna.' : resource.error.message}</p>
  return children
}
function Cursor({ before, data, setBefore }) {
  return <div className="ch-community-pager"><button disabled={!before} onClick={()=>setBefore(null)}>Najnowsze</button><button disabled={!data?.next_before} onClick={()=>setBefore(data.next_before)}>Starsze</button></div>
}
function Person({ person }) {
  return <Link className="ch-player-link" to={`/players/${person.steamid}`}><img src={person.avatar_url || '/images/cloudhub/avatar.svg'} alt=""/><span>{person.nickname}<small>{person.steamid}</small></span></Link>
}
export function Relationship({ target, onChanged = () => {} }) {
  const [revision, setRevision]=useState(0), [busy,setBusy]=useState(false), [error,setError]=useState('')
  const resource=useCommunityResource(endpoint('relation',{target}),revision), r=resource.data
  const run=async action=>{
    if (['remove','block','reject','cancel'].includes(action) && !window.confirm(action==='block'?'Zablokować gracza? Znajomość i zaproszenia zostaną usunięte. Odblokowanie ich nie przywróci.':`${labels[action]}?`)) return
    setBusy(true);setError('')
    try { await communityRequest(endpoint(action),{target});setRevision(v=>v+1);onChanged() } catch(e) {setError(e.message)} finally {setBusy(false)}
  }
  const actions=r?.status==='none'?['invite','block']:r?.status==='sent'?['cancel','block']:r?.status==='received'?['accept','reject','block']:r?.status==='friends'?['remove','block']:r?.blocked_by_me?['unblock']:[]
  return <div className="ch-social-relation"><Resource resource={resource}>{r&&<><span>{states[r.status]}</span><div className="ch-social-actions">{actions.map(a=><button key={a} disabled={busy} onClick={()=>run(a)}>{labels[a]}</button>)}</div></>}</Resource>{error&&<p role="alert">{error}</p>}</div>
}
export function SocialSettings({ onSaved }) {
  const r=useCommunityResource(endpoint('settings'))
  return <Resource resource={r}>{r.data&&<SettingsForm initial={r.data} onSaved={onSaved}/>}</Resource>
}
function SettingsForm({ initial,onSaved }) {
  const [v,setV]=useState(initial),[busy,setBusy]=useState(false),[error,setError]=useState('')
  const fields=['bio','profile_visibility','activity_visibility','friends_visibility','invites_policy','comments_policy','theme','banner']
  const visibility=[['public','Publiczny'],['friends','Tylko znajomi'],['private','Prywatny']]
  const policies=[['everyone','Wszyscy'],['friends','Tylko znajomi'],['disabled','Nikt']]
  const select=(key,label,options)=><label key={key}>{label}<select aria-label={label} value={v[key]} onChange={e=>setV({...v,[key]:e.target.value})}>{options.map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label>
  const submit=async e=>{e.preventDefault();setBusy(true);setError('');try {await communityRequest(endpoint('settings'),Object.fromEntries(fields.map(k=>[k,v[k]])));onSaved()} catch(e){setError(e.message)}finally{setBusy(false)}}
  return <form className="ch-community-card ch-profile-edit ch-social-settings" onSubmit={submit}><h2>Ustawienia profilu</h2><label>Opis profilu<textarea rows={4} maxLength={1000} value={v.bio} onChange={e=>setV({...v,bio:e.target.value})}/></label>
    {select('profile_visibility','Widoczność profilu',visibility)}{select('activity_visibility','Ostatnia aktywność',visibility)}{select('friends_visibility','Widoczność znajomych',visibility)}{select('invites_policy','Kto może zapraszać',policies)}{select('comments_policy','Kto może komentować',policies)}
    {select('theme','Motyw kolorystyczny',[['cloudhub','CloudHub · błękit'],['violet','Fiolet'],['emerald','Szmaragd'],['amber','Bursztyn']])}{select('banner','Nagłówek',[['default','Chmury'],['aurora','Zorza'],['grid','Siatka'],['sunset','Zachód']])}
    <p>„Tylko znajomi” dla zaproszeń zamyka nowe znajomości. Listy CloudHub i Steam są niezależne. Ukrycie profilu nie usuwa komentarzy opublikowanych u innych graczy.</p>
    <button disabled={busy}>{busy?'Zapisywanie…':'Zapisz profil'}</button>{error&&<p role="alert">{error}</p>}
  </form>
}
export function FriendsList({ target, revision=0, manage=false, onChanged=()=>{} }) {
  const [page,setPage]=useState(1),r=useCommunityResource(endpoint('friends',{target,page}),revision)
  return <section className="ch-community-card"><h2>Znajomi {r.data&&<small>({r.data.total})</small>}</h2><Resource resource={r}>{r.data&&<>{r.data.items.length?<div className="ch-social-people">{r.data.items.map(p=><div key={p.steamid}><Person person={p}/>{manage&&<Relationship target={p.steamid} onChanged={onChanged}/>}</div>)}</div>:<p>Lista znajomych jest pusta.</p>}<div className="ch-community-pager"><button disabled={page===1} onClick={()=>setPage(p=>p-1)}>Poprzednia</button><span>Strona {page}</span><button disabled={page*20>=r.data.total} onClick={()=>setPage(p=>p+1)}>Następna</button></div></>}</Resource></section>
}
export function ProfileSocial({ target,user }) {
  const [revision,setRevision]=useState(0)
  return <>{user&&user.steamid!==target&&<section className="ch-community-card"><Relationship target={target} onChanged={()=>setRevision(v=>v+1)}/></section>}<FriendsList key={target} target={target} revision={revision}/><Comments key={target+':'+revision} target={target} user={user}/></>
}
function Comments({ target,user }) {
  const [before,setBefore]=useState(null),[revision,setRevision]=useState(0),[body,setBody]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('')
  const r=useCommunityResource(endpoint('comments',{target,...(before?{before}:{})}),revision)
  const act=async(action,fields)=>{setBusy(true);setError('');try{await communityRequest(endpoint(action),fields);setRevision(v=>v+1);if(action==='comment'){setBody('');setBefore(null)}}catch(e){setError(e.message)}finally{setBusy(false)}}
  const remove=c=>{if(!window.confirm('Usunąć komentarz?'))return;const reason=c.moderation_required?window.prompt('Powód moderacji (obowiązkowy):'):'';if(reason===null)return;act('delete',{id:c.id,reason})}
  const report=c=>{const reason=window.prompt('Dlaczego zgłaszasz ten komentarz?');if(reason?.trim())act('report',{id:c.id,reason})}
  return <section className="ch-community-card"><h2>Komentarze</h2><Resource resource={r}>{r.data&&<>{r.data.items.length?r.data.items.map(c=><article className="ch-social-comment" id={'comment-'+c.id} key={c.id}><header><Person person={{...c,steamid:c.author}}/><time>{time(c.created_at)}</time></header><p className="ch-profile-bio">{c.body}</p><div className="ch-social-actions">{c.can_delete&&<button disabled={busy} onClick={()=>remove(c)}>Usuń komentarz</button>}{user&&<button disabled={busy} onClick={()=>report(c)}>Zgłoś</button>}</div></article>):<p>Brak komentarzy. Rozpocznij rozmowę.</p>}<Cursor before={before} data={r.data} setBefore={setBefore}/></>}</Resource>
    {user?<form className="ch-profile-edit" onSubmit={e=>{e.preventDefault();act('comment',{target,body})}}><label>Twój komentarz<textarea required maxLength={1000} rows={3} value={body} onChange={e=>setBody(e.target.value)}/></label><small>{body.length}/1000 · Publikacja zależy od ustawień prywatności gracza.</small><button disabled={busy||!body.trim()}>Dodaj komentarz</button></form>:<p>Zaloguj się przez Steam, aby komentować.</p>}{error&&<p role="alert">{error}</p>}
  </section>
}
export function FriendsPage({ user }) {
  const [kind,setKind]=useState('received'),[page,setPage]=useState(1),[revision,setRevision]=useState(0)
  const r=useCommunityResource(endpoint('connections',{kind,page}),revision)
  return <><div className="ch-community-heading"><p className="ch-eyebrow">CLOUDHUB / SPOŁECZNOŚĆ</p><h1>Twoi znajomi<span>.</span></h1><p>Ekipa do kolejnej rundy. <Link to="/players">Znajdź gracza po nicku lub SteamID64</Link></p></div><FriendsList target={user.steamid} revision={revision} manage onChanged={()=>setRevision(v=>v+1)}/><DirectConnection/><section className="ch-community-card"><h2>Zaproszenia i blokady</h2><label>Lista<select value={kind} onChange={e=>{setKind(e.target.value);setPage(1)}}><option value="received">Otrzymane</option><option value="sent">Wysłane</option><option value="blocked">Zablokowani</option></select></label><Resource resource={r}>{r.data&&<>{r.data.items.length?r.data.items.map(p=><article className="ch-social-comment" key={p.steamid}><Person person={p}/><Relationship target={p.steamid} onChanged={()=>setRevision(v=>v+1)}/></article>):<p>Brak pozycji na tej liście.</p>}<div className="ch-community-pager"><button disabled={page===1} onClick={()=>setPage(p=>p-1)}>Poprzednia</button><button disabled={page*20>=r.data.total} onClick={()=>setPage(p=>p+1)}>Następna</button></div></>}</Resource></section></>
}
function DirectConnection() {
  const [draft,setDraft]=useState(''),[target,setTarget]=useState('')
  return <section className="ch-community-card"><h2>Znajdź konto po SteamID64</h2><form className="ch-profile-edit" onSubmit={e=>{e.preventDefault();setTarget(draft)}}><label>SteamID64 gracza<input required pattern="7656119[0-9]{10}" maxLength={17} value={draft} onChange={e=>setDraft(e.target.value)}/></label><button>Sprawdź relację</button></form>{target&&<Relationship key={target} target={target}/>}</section>
}
export function NotificationLink({ user }) {
  const [revision,setRevision]=useState(0)
  useEffect(()=>{if(!user)return;const timer=setInterval(()=>{if(!document.hidden)setRevision(v=>v+1)},60000);return()=>clearInterval(timer)},[user])
  const r=useCommunityResource(user?endpoint('notifications'):null,revision)
  return user?<Link to="/notifications">Powiadomienia{r.data?.unread>0?` (${r.data.unread})`:''}</Link>:null
}
export function NotificationsPage() {
  const [before,setBefore]=useState(null),[revision,setRevision]=useState(0),[error,setError]=useState(''),[busy,setBusy]=useState(false)
  useEffect(()=>{const timer=setInterval(()=>{if(!document.hidden)setRevision(v=>v+1)},60000);return()=>clearInterval(timer)},[])
  const r=useCommunityResource(endpoint('notifications',before?{before}:{}),revision)
  const read=async id=>{setBusy(true);setError('');try{await communityRequest(endpoint('read'),{id});setRevision(v=>v+1)}catch(e){setError(e.message)}finally{setBusy(false)}}
  const titles={invite:'Nowe zaproszenie do znajomych',accepted:'Zaproszenie zostało zaakceptowane',comment:'Nowy komentarz na Twoim profilu',moderated:'Twój komentarz został usunięty przez moderację'}
  return <section className="ch-community-card"><h1>Powiadomienia</h1><Resource resource={r}>{r.data&&<><p>Nieprzeczytane: {r.data.unread}</p>{r.data.items.length?r.data.items.map(n=><article className={'ch-social-comment '+(!n.read_at?'is-unread':'')} key={n.id}><h2>{titles[n.kind]}</h2><time>{time(n.created_at)}</time><div className="ch-social-actions"><Link to={n.kind==='invite'?'/friends':`/players/${n.profile_id}`}>Otwórz</Link>{!n.read_at&&<button disabled={busy} onClick={()=>read(n.id)}>Oznacz jako przeczytane</button>}</div></article>):<p>Nie masz jeszcze powiadomień.</p>}<Cursor before={before} data={r.data} setBefore={setBefore}/></>}</Resource>{error&&<p role="alert">{error}</p>}</section>
}
export function ModerationPanel() {
  const [before,setBefore]=useState(null),[auditBefore,setAuditBefore]=useState(null),[revision,setRevision]=useState(0),[error,setError]=useState(''),[busy,setBusy]=useState(false)
  const reports=useCommunityResource(endpoint('moderation',before?{before}:{}),revision),audit=useCommunityResource(endpoint('audit',auditBefore?{before:auditBefore}:{}),revision)
  const act=async(action,id)=>{if(!window.confirm(action==='delete'?'Usunąć zgłoszony komentarz?':'Zamknąć zgłoszenia tego komentarza?'))return;const reason=window.prompt('Powód decyzji (obowiązkowy):');if(!reason?.trim())return;setBusy(true);setError('');try{await communityRequest(endpoint(action),{id,reason});setRevision(v=>v+1)}catch(e){setError(e.message)}finally{setBusy(false)}}
  return <><section className="ch-community-card"><h2>Moderacja społeczności</h2><Resource resource={reports}>{reports.data&&<>{reports.data.items.length?reports.data.items.map(r=><article className="ch-social-comment" key={r.id}><Link to={`/players/${r.profile_id}`}>Profil · {r.profile_id}</Link><p className="ch-profile-bio">{r.body}</p><p>Zgłoszenie: {r.reason}</p><small>Autor: {r.author} · Zgłaszający: {r.reporter}</small><div className="ch-social-actions"><button disabled={busy} onClick={()=>act('delete',r.comment_id)}>Usuń komentarz</button><button disabled={busy} onClick={()=>act('resolve',r.comment_id)}>Zamknij zgłoszenie</button></div></article>):<p>Brak otwartych zgłoszeń.</p>}<Cursor before={before} data={reports.data} setBefore={setBefore}/></>}</Resource>{error&&<p role="alert">{error}</p>}</section><section className="ch-community-card"><h2>Historia moderacji</h2><Resource resource={audit}>{audit.data&&<>{audit.data.items.length?audit.data.items.map(r=><article className="ch-social-comment" key={r.id}><strong>{r.action}</strong><p>{r.reason}</p><small>{time(r.created_at)} · {r.actor_steamid}</small></article>):<p>Brak działań.</p>}<Cursor before={auditBefore} data={audit.data} setBefore={setAuditBefore}/></>}</Resource></section></>
}
