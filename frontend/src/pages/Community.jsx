import { useState } from 'react'
import { ArrowUpRight } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { SocialSettings, ProfileSocial, FriendsPage, NotificationsPage, ModerationPanel } from '../components/Social'
import { ShopPage, InventoryPage, ShopAdmin } from '../components/Shop'
import Badges from '../components/Badges'
import Brand from '../components/Brand'
import SiteFooter from '../components/SiteFooter'
import CommunityNav from '../components/CommunityNav'
import { communityRequest, useCommunityResource, useSocialCapabilities } from '../lib/community'
import { apiUrl } from '../lib/api'
import '../styles/Community.css'

const number = value => value == null ? '—' : new Intl.NumberFormat('pl-PL').format(BigInt(value))
const date = value => value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString('pl-PL') : 'Ukryta'
function State({ resource, children }) {
  if (resource.loading) return <p className="ch-community-state" role="status">Wczytywanie danych…</p>
  if (resource.error) return <div className="ch-community-state" role="alert"><h2>{resource.error.status === 404 ? 'Dane niedostępne' : 'Nie udało się wczytać danych'}</h2><p>{resource.error.status === 404 ? 'Profil jest prywatny, nie istnieje lub CloudHub Core nie został jeszcze włączony.' : resource.error.message}</p><button onClick={() => window.location.reload()}>Spróbuj ponownie</button></div>
  return children
}
function Empty({ children = 'Nie ma jeszcze wyników. Wróć po kolejnych rozgrywkach.' }) {
  return <div className="ch-community-state"><span aria-hidden="true">◇</span><h2>Brak danych</h2><p>{children}</p></div>
}
function Pager({ page, total, limit, onChange }) {
  return <div className="ch-community-pager"><button disabled={page <= 1} onClick={() => onChange(page - 1)}>Poprzednia</button><span>Strona {page} · {total} graczy</span><button disabled={page * limit >= total} onClick={() => onChange(page + 1)}>Następna</button></div>
}
function PlayerTable({ rows, user, onSelect, selectionLocked = false }) {
  return <div className="ch-community-table-wrap"><table className="ch-community-table"><thead><tr><th>Pozycja</th><th>Gracz</th><th>RP</th><th>Zabójstwa</th><th>K/D</th><th>Headshoty</th><th>Wygrane rundy</th>{onSelect && <th>Portfel</th>}</tr></thead><tbody>
    {rows.map(p => <tr key={p.steamid} className={p.steamid === user?.steamid ? 'is-current-player' : ''}>
      <td>#{number(p.position)}</td><td><Link className="ch-player-link" to={`/players/${p.steamid}`}><img src={p.avatar_url || '/images/cloudhub/avatar.svg'} alt="" loading="lazy"/><span>{p.nickname}{p.steamid === user?.steamid && <small>Ty</small>}</span></Link><Badges stats={p}/></td>
      <td>{number(p.rp)}</td><td>{number(p.kills)}</td><td>{p.kd}</td><td>{number(p.headshots)}</td><td>{p.win_rate == null ? '—' : `${p.win_rate}%`}</td>{onSelect && <td>{number(p.balance)} CC<button disabled={selectionLocked} onClick={() => onSelect(p)}>Korekta CC</button></td>}
    </tr>)}
  </tbody></table></div>
}
function Leaderboard({ user, directory = false }) {
  const [params, setParams] = useSearchParams()
  const [draft, setDraft] = useState(params.get('search') || '')
  const page = Math.max(1, Number(params.get('page')) || 1), limit = Number(params.get('limit')) || 10
  const update = values => { const next = new URLSearchParams(params); Object.entries({ page: '1', ...values }).forEach(([k,v]) => v ? next.set(k,String(v)) : next.delete(k)); setParams(next) }
  const seasons = useCommunityResource('community.php?action=seasons')
  const query = new URLSearchParams({ action:'leaderboard', search:params.get('search') || '', sort:params.get('sort') || 'rp', limit:String(limit), page:String(page) })
  if (params.get('season')) query.set('season',params.get('season'))
  const resource = useCommunityResource('community.php?' + query)
  return <><div className="ch-community-heading"><p className="ch-eyebrow">CLOUDHUB / COMMUNITY</p><h1>{directory ? 'Profile graczy' : 'Ranking graczy'}<span>.</span></h1><p>{directory ? 'Znajdź gracza i poznaj jego wyniki.' : 'Każda runda się liczy. Sprawdź swoją pozycję.'}</p></div>
    <div className="ch-community-filters"><form onSubmit={e => { e.preventDefault(); update({search:draft}) }}><label htmlFor="player-search">Nick lub SteamID64</label><div><input id="player-search" value={draft} onChange={e => setDraft(e.target.value)} maxLength={128} placeholder="Szukaj gracza…"/><button type="submit">Szukaj</button></div></form>
      <label>Zakres<select value={params.get('season') || ''} onChange={e => update({season:e.target.value})}><option value="">Globalny</option>{seasons.data?.seasons.map(s => <option key={s.id} value={s.id}>{s.name} · {s.status === 'closed' ? 'zakończony' : 'aktywny'}</option>)}</select></label>
      <label>Sortowanie<select value={params.get('sort') || 'rp'} onChange={e => update({sort:e.target.value})}>{[['rp','RP'],['kills','Zabójstwa'],['kd','K/D'],['headshots','Headshoty'],['win_rate','Wygrane rundy %']].map(([v,l]) => <option key={v} value={v}>{l}</option>)}</select></label>
      <label>Na stronę<select value={limit} onChange={e => update({limit:e.target.value})}>{[10,50,100].map(n => <option key={n} value={n}>TOP {n}</option>)}</select></label>
    </div>
    {seasons.error && <p role="alert">Nie udało się pobrać listy sezonów.</p>}
    <State resource={resource}>{resource.data && <>{resource.data.players.length ? <PlayerTable rows={resource.data.players} user={user}/> : <Empty>Brak graczy pasujących do wybranych filtrów.</Empty>}<Pager {...resource.data} onChange={page => update({page})}/></>}</State>
    <p className="ch-community-note">TOP: RP malejąco, następnie zabójstwa, a przy remisie SteamID64 rosnąco. Zmiana sortowania nie zmienia odznaki TOP. Prywatne profile są ukryte, więc numery pozycji mogą mieć przerwy. K/D = zabójstwa / max(zgony, 1).</p>
  </>
}
function ProfileEditor({ profile, onSaved }) {
  const [bio,setBio] = useState(profile.bio || ''), [visibility,setVisibility] = useState(profile.profile_visibility === 'public' ? 'public' : 'private'), [activity,setActivity] = useState(profile.activity_visibility === 'public' ? 'public' : 'private')
  const [busy,setBusy] = useState(false), [message,setMessage] = useState('')
  const submit = async e => { e.preventDefault(); setBusy(true); setMessage(''); try { await communityRequest('community.php?action=edit',{bio,profile_visibility:visibility,activity_visibility:activity}); onSaved() } catch(error) {setMessage(error.message)} finally {setBusy(false)} }
  return <form className="ch-community-card ch-profile-edit" onSubmit={submit}><h2>Ustawienia profilu</h2><label>Opis profilu<textarea maxLength={1000} rows={4} value={bio} onChange={e => setBio(e.target.value)}/></label>
    <label>Widoczność profilu<select value={visibility} onChange={e=>setVisibility(e.target.value)}><option value="public">Publiczny</option><option value="private">Prywatny</option></select></label>
    <label>Ostatnia aktywność<select value={activity} onChange={e=>setActivity(e.target.value)}><option value="private">Prywatna</option><option value="public">Publiczna</option></select></label><p>Profil prywatny ukrywa opis, wyniki i wpis w publicznym rankingu. OWNER zachowuje dostęp do statystyk. RP, ranga i CC są wyliczane przez serwer.</p><button disabled={busy} type="submit">{busy?'Zapisywanie…':'Zapisz profil'}</button>{message && <p role="alert">{message}</p>}</form>
}
function Profile({ user }) {
  const { steamid } = useParams(), [revision,setRevision] = useState(0)
  const resource = useCommunityResource('community.php?action=profile&steamid='+encodeURIComponent(steamid),revision)
  const p=resource.data?.profile, s=p?.stats
  // The profile projection reports whether the social module (flag and migration) is active.
  const socialEnabled=!!p?.social_enabled
  return <State resource={resource}>{p && <><div className={`ch-profile-hero ch-community-card ch-theme-${p.theme} ch-banner-${p.banner}`}><img src={p.avatar_url || '/images/cloudhub/avatar.svg'} alt=""/><div><p className="ch-eyebrow">PROFIL GRACZA</p><h1>{p.nickname}</h1><code>{p.steamid}</code><Badges stats={s}/><a href={`https://steamcommunity.com/profiles/${p.steamid}`} target="_blank" rel="noreferrer">Profil Steam <ArrowUpRight size={14} aria-hidden="true"/></a></div></div>
    {revision>0 && <p role="status">Profil zapisany.</p>}
    <div className="ch-community-card"><h2>O graczu</h2><p className="ch-profile-bio">{p.bio || 'Gracz nie udostępnił opisu.'}</p><p>Ostatnia aktywność konta: {date(p.last_seen_at)}</p></div>
    {s && <div className="ch-stat-grid">{[['RP',number(s.rp)],['Zabójstwa',number(s.kills)],['Zgony',number(s.deaths)],['Asysty',number(s.assists)],['Headshoty',number(s.headshots)],['K/D',s.kd],['Wygrane rundy',number(s.rounds_won)],['Win rate rund',s.win_rate == null ? '—' : s.win_rate+'%'],['Aktywny czas gry',`${number(String(BigInt(s.play_seconds)/60n))} min`]].map(([label,value])=><article className="ch-community-card" key={label}><span>{label}</span><strong>{value}</strong></article>)}</div>}
    <section className="ch-community-card"><h2>Historia sezonów</h2>{p.seasons.length ? p.seasons.map(season=><div className="ch-season-entry" key={season.season_id}><strong>{season.name}</strong><span>{number(season.rp)} RP</span><span>{season.final_position ? `Miejsce końcowe: #${number(season.final_position)}`:'Miejsce końcowe nie zostało zapisane'}</span><Badges stats={season}/></div>):<p>Brak zapisanych sezonów.</p>}</section>
    {socialEnabled && <ProfileSocial target={p.steamid} user={user}/>}
    {p.editable && user && socialEnabled && <SocialSettings key={p.steamid+':social:'+revision} onSaved={()=>setRevision(v=>v+1)}/>}
    {p.editable && user && !socialEnabled && <ProfileEditor key={p.steamid+':'+revision} profile={p} onSaved={()=>setRevision(v=>v+1)}/>}
  </>}</State>
}
function Wallet() {
  const [params,setParams]=useSearchParams()
  const account=useCommunityResource('cloudhub.php'), history=useCommunityResource('cloudhub.php?action=history'+(params.get('before')?'&before='+encodeURIComponent(params.get('before')):''))
  return <><div className="ch-community-heading"><p className="ch-eyebrow">CLOUDHUB / WALLET</p><h1>Cloud Coins<span>.</span></h1><p>Twoje saldo i prywatna historia transakcji.</p></div><State resource={account}>{account.data && <div className="ch-wallet-balance ch-community-card"><span>Dostępne środki</span><strong>{number(account.data.account.balance)} <small>CC</small></strong><p>Nagrody za rundy trafiają tutaj automatycznie.</p></div>}</State>
    <h2>Historia i nagrody</h2><State resource={history}>{history.data && <>{history.data.transactions.length ? <div className="ch-ledger">{history.data.transactions.map(tx=><article className="ch-community-card ch-ledger-row" key={tx.id}><div><strong>{tx.source==='round'?'Nagroda za rundę':tx.source==='admin'?'Korekta administracyjna':tx.source==='shop'?'Zakup w sklepie':tx.source}</strong><p>{tx.reason}</p><small>{date(tx.created_at)} · #{tx.id}</small></div><div><strong className={tx.amount.startsWith('-')?'ch-amount-debit':'ch-amount-credit'}>{tx.amount.startsWith('-')?'':'+'}{number(tx.amount)} CC</strong><small>Saldo po operacji: {number(tx.balance_after)} CC</small></div></article>)}</div>:<Empty>Twój portfel nie ma jeszcze transakcji.</Empty>}<div className="ch-community-pager"><button disabled={!params.get('before')} onClick={()=>setParams({})}>Najnowsze</button><button disabled={!history.data.next_before} onClick={()=>setParams({before:history.data.next_before})}>Starsze</button></div></>}</State>
  </>
}
function Adjustment({ player, enabled, onSaved, pending, updatePending }) {
  const [amount,setAmount]=useState(pending?.amount || ''),[reason,setReason]=useState(pending?.reason || ''),[busy,setBusy]=useState(false),[message,setMessage]=useState('')
  const submit = async e => {
    e.preventDefault()
    setBusy(true); setMessage('')
    try {
      const payload = pending || { steamid:player.steamid, amount, reason, request_id:crypto.randomUUID() }
      // Persist before sending so a reload or navigation can retry the exact same operation.
      updatePending(payload)
      const result = await communityRequest('admin.php?action=adjust',payload)
      updatePending(null); setAmount(''); setReason('')
      setMessage(`Zapisano operację #${result.transaction_id}. Saldo po operacji: ${number(result.balance)} CC.`)
      onSaved()
    } catch(error) {
      setMessage(error.message)
      if (error.status >= 400 && error.status < 500) updatePending(null)
    } finally { setBusy(false) }
  }
  return <form className="ch-community-card ch-adjustment" onSubmit={submit}><h2>Korekta CC · {player.nickname}</h2><code>{player.steamid}</code>{!enabled&&<p>Operacje CC są wyłączone w konfiguracji serwera.</p>}<label>Kwota (minus oznacza odjęcie)<input required type="number" min="-1000000" max="1000000" step="1" value={amount} onChange={e=>setAmount(e.target.value)} disabled={!enabled||!!pending}/></label><label>Powód operacji<input required maxLength={500} value={reason} onChange={e=>setReason(e.target.value)} disabled={!enabled||!!pending}/></label><button disabled={!enabled||busy} type="submit">{busy?'Zapisywanie…':pending?'Ponów tę samą operację':'Zapisz korektę CC'}</button>{pending&&!busy&&<p>Wynik wymaga potwierdzenia. Ponów tę samą operację — identyfikator chroni przed podwójnym naliczeniem.</p>}{message&&<p role="status">{message}</p>}</form>
}
function Admin({ user }) {
  const pendingKey = 'cloudhub-pending-cc:' + user.steamid
  const [pending,setPending] = useState(() => {
    try { return JSON.parse(sessionStorage.getItem(pendingKey) || 'null') } catch { return null }
  })
  const updatePending = payload => {
    try {
      if (payload) sessionStorage.setItem(pendingKey,JSON.stringify(payload))
      else sessionStorage.removeItem(pendingKey)
    } catch { throw new Error('Nie można zapisać identyfikatora operacji w tej karcie. Włącz pamięć sesji przeglądarki.') }
    setPending(payload)
  }
  const [search,setSearch]=useState(''),[query,setQuery]=useState(''),[page,setPage]=useState(1),[revision,setRevision]=useState(0),[selected,setSelected]=useState(pending ? {steamid:pending.steamid,nickname:pending.steamid} : null),[before,setBefore]=useState(null)
  const players=useCommunityResource('admin.php?action=players&'+new URLSearchParams({search:query,page}),revision), config=useCommunityResource('admin.php?action=config'), audit=useCommunityResource('admin.php?action=audit'+(before?'&before='+before:''),revision)
  const social=useSocialCapabilities(user)
  return <><div className="ch-community-heading"><p className="ch-eyebrow">CLOUDHUB / OWNER</p><h1>Administracja<span>.</span></h1><p>Gracze, Cloud Coins i dziennik operacji.</p></div><State resource={players}>{players.data && <><form className="ch-community-search" onSubmit={e=>{e.preventDefault();setQuery(search);setPage(1)}}><label htmlFor="admin-search">Znajdź gracza</label><input id="admin-search" value={search} onChange={e=>setSearch(e.target.value)} maxLength={128}/><button>Szukaj</button></form>{players.data.players.length?<PlayerTable rows={players.data.players} user={user} onSelect={setSelected} selectionLocked={!!pending}/>:<Empty/>}<Pager {...players.data} onChange={setPage}/></>}</State>
    {selected&&<Adjustment key={selected.steamid} player={selected} pending={pending} updatePending={updatePending} enabled={config.data?.adjustments_enabled} onSaved={()=>setRevision(v=>v+1)}/>}
    <ShopAdmin/>{social?.moderate&&<ModerationPanel/>}<section className="ch-community-card"><h2>Dziennik administracyjny</h2><State resource={audit}>{audit.data && <>{audit.data.entries.length?audit.data.entries.map(e=><article className="ch-audit-entry" key={e.id}><strong>#{e.id} · {e.action}</strong><p>{e.reason}</p><small>{date(e.created_at)} · Administrator: {e.actor_steamid||'CLI'} · Gracz: {e.target_steamid||'—'}</small><pre>{e.metadata_json}</pre></article>):<p>Brak operacji.</p>}<div className="ch-community-pager"><button disabled={!before} onClick={()=>setBefore(null)}>Najnowsze</button><button disabled={!audit.data.next_before} onClick={()=>setBefore(audit.data.next_before)}>Starsze</button></div></>}</State></section>
    <section className="ch-community-card"><h2>Rangi i punktacja</h2><p>Edycja WWW jest wyłączona. Zmiany zasad wymagają wersjonowania konfiguracji i sprawdzenia wpływu na oczekujące zdarzenia Core.</p><State resource={config}>{config.data && <><div className="ch-config-grid">{config.data.ranks.map(r=><p key={r.rank_key}><strong>{r.name}</strong> od {number(r.min_rp)} RP</p>)}</div><details><summary>Reguły punktacji i antyfarm</summary><dl className="ch-config-grid">{config.data.rewards.map(r=><div key={r.rule_key}><dt>{r.rule_key}</dt><dd>{r.value}</dd></div>)}</dl></details></>}</State></section>
  </>
}
export default function Community({ user, mode }) {
  const needsLogin=['wallet','admin','friends','notifications','moderation','inventory'].includes(mode)
  return <div className="ch-community-page"><header className="ch-community-header"><Link to="/" aria-label="CloudHub CS2"><Brand/></Link><Link to="/team">Konfigurator skinów</Link><Link to="/download">Pobierz CloudHub</Link>{!user&&<a href={apiUrl('login-steam.php')}>Zaloguj przez Steam <ArrowUpRight size={14} aria-hidden="true"/></a>}</header><CommunityNav user={user}/><main className="ch-community-main">
    {needsLogin&&!user?<div className="ch-community-state"><h1>Zaloguj się przez Steam</h1><p>Ta część CloudHub jest dostępna po zalogowaniu.</p><a className="ch-primary" href={apiUrl('login-steam.php')}>Zaloguj przez Steam</a></div>:mode==='friends'?<FriendsPage user={user}/>:mode==='notifications'?<NotificationsPage/>:mode==='moderation'?<ModerationPanel/>:mode==='profile'?<Profile user={user}/>:mode==='wallet'?<Wallet/>:mode==='shop'?<ShopPage user={user}/>:mode==='inventory'?<InventoryPage/>:mode==='admin'?<Admin user={user}/>:<Leaderboard user={user} directory={mode==='players'}/>}
  </main><SiteFooter/></div>
}
