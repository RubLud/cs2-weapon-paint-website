import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { communityRequest, useCommunityResource } from '../lib/community'
import { fetchJsonCached } from '../lib/dataCache'
import { askConfirm } from '../lib/dialogs'
import '../styles/Shop.css'

const number = value => value == null ? '—' : new Intl.NumberFormat('pl-PL').format(BigInt(value))
const date = value => value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString('pl-PL') : ''
const kinds = [['all','Wszystko'],['weapon_skin','Skiny broni'],['knife','Noże'],['glove','Rękawice']]
const kindLabel = Object.fromEntries(kinds)
let imageIndex
/** Product images come from the same bundled WeaponPaints catalogs as the configurator. */
function useProductImages() {
  const [images, setImages] = useState(null)
  useEffect(() => {
    let live = true
    imageIndex ||= Promise.all([fetchJsonCached('/data/skins_en.json'), fetchJsonCached('/data/gloves_en.json')])
      .then(lists => new Map(lists.flat().map(item => [`${item.weapon_defindex}:${Number(item.paint)}`, item.image])))
      .catch(() => new Map())
    imageIndex.then(map => { if (live) setImages(map) })
    return () => { live = false }
  }, [])
  return images
}
function ShopState({ resource, children }) {
  if (resource.loading) return <p className="ch-community-state" role="status">Wczytywanie sklepu…</p>
  if (resource.error) return <div className="ch-community-state" role="alert"><h2>{resource.error.status === 404 ? 'Sklep jest wyłączony' : 'Nie udało się wczytać sklepu'}</h2><p>{resource.error.status === 404 ? 'Sklep Cloud Coins nie został jeszcze uruchomiony na tym serwerze.' : resource.error.message}</p></div>
  return children
}
function ProductCard({ product, images, children }) {
  const image = images?.get(`${product.weapon_defindex}:${product.paint_id}`)
  return <article className="ch-shop-card ch-community-card">
    <div className="ch-shop-image">{image ? <img src={image} alt="" loading="lazy"/> : <span aria-hidden="true">◇</span>}</div>
    <small>{kindLabel[product.kind]}</small><h3>{product.name}</h3>{children}
  </article>
}
const pendingKey = steamid => 'cloudhub-pending-purchase:' + steamid
function readPending(steamid) { try { return JSON.parse(sessionStorage.getItem(pendingKey(steamid)) || 'null') } catch { return null } }
function writePending(steamid, payload) {
  try { payload ? sessionStorage.setItem(pendingKey(steamid), JSON.stringify(payload)) : sessionStorage.removeItem(pendingKey(steamid)) }
  catch { throw new Error('Nie można zapisać identyfikatora zakupu w tej karcie. Włącz pamięć sesji przeglądarki.') }
}
export function ShopPage({ user }) {
  const [params, setParams] = useSearchParams()
  const [draft, setDraft] = useState(params.get('search') || '')
  const [revision, setRevision] = useState(0), [busy, setBusy] = useState(null), [message, setMessage] = useState(null)
  const [pending, setPending] = useState(() => user ? readPending(user.steamid) : null)
  const images = useProductImages()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const update = values => { const next = new URLSearchParams(params); Object.entries({ page:'1', ...values }).forEach(([k,v]) => v && v !== 'all' ? next.set(k,String(v)) : next.delete(k)); setParams(next) }
  const query = new URLSearchParams({ action:'catalog', kind:params.get('kind') || 'all', sort:params.get('sort') || 'price_asc', search:params.get('search') || '', page:String(page) })
  const catalog = useCommunityResource('shop.php?' + query, revision, { keep:true })
  const account = useCommunityResource(user ? 'cloudhub.php' : null, revision, { keep:true })
  const storePending = payload => { writePending(user.steamid, payload); setPending(payload) }
  const send = async payload => {
    setBusy(payload.product_id); setMessage(null)
    try {
      // Persist before sending: after a lost response the retry reuses the same request ID and is charged once.
      storePending(payload)
      const result = await communityRequest('shop.php?action=buy', { product_id:payload.product_id, request_id:payload.request_id, expected_price:payload.expected_price })
      storePending(null)
      setMessage({ ok:true, text:`${result.status === 'duplicate' ? 'Zakup był już zapisany' : 'Kupiono'}: ${payload.name}. Saldo: ${number(result.balance)} CC.` })
      setRevision(v => v + 1)
    } catch (error) {
      if (error.status >= 400 && error.status < 500) storePending(null)
      setMessage({ ok:false, text:error.message })
    } finally { setBusy(null) }
  }
  const buy = async product => {
    if (!await askConfirm({ title:'Potwierdź zakup', message:`Kupić ${product.name} za ${number(product.price_cc)} CC? Personalizacja (float, pattern, naklejki, zawieszki, StatTrak, nametag) jest darmowa.`, confirmLabel:'Kup', cancelLabel:'Anuluj' })) return
    send({ product_id:String(product.id), request_id:crypto.randomUUID(), expected_price:String(product.price_cc), name:product.name })
  }
  const data = catalog.data, balance = account.data?.account.balance
  return <>
    <div className="ch-community-heading"><p className="ch-eyebrow">CLOUDHUB / SHOP</p><h1>Sklep<span>.</span></h1><p>Za Cloud Coins odblokowujesz bazowy skin, nóż lub rękawice na stałe. Cała personalizacja w konfiguratorze pozostaje darmowa.</p></div>
    {user && balance != null && <p className="ch-shop-balance">Twoje saldo: <strong>{number(balance)} CC</strong> · <Link to="/inventory">Ekwipunek</Link></p>}
    {pending && <div className="ch-community-card" role="status"><p>Zakup „{pending.name}” czeka na potwierdzenie serwera.</p><button disabled={!!busy} onClick={() => send(pending)}>Ponów ten sam zakup</button></div>}
    <div className="ch-community-filters">
      <form onSubmit={e => { e.preventDefault(); update({ search:draft }) }}><label htmlFor="shop-search">Nazwa przedmiotu</label><div><input id="shop-search" value={draft} onChange={e => setDraft(e.target.value)} maxLength={64} placeholder="np. AK-47 | Redline"/><button type="submit">Szukaj</button></div></form>
      <label>Kategoria<select value={params.get('kind') || 'all'} onChange={e => update({ kind:e.target.value })}>{kinds.map(([v,l]) => <option key={v} value={v}>{l}</option>)}</select></label>
      <label>Sortowanie<select value={params.get('sort') || 'price_asc'} onChange={e => update({ sort:e.target.value })}><option value="price_asc">Cena rosnąco</option><option value="price_desc">Cena malejąco</option><option value="name">Nazwa</option></select></label>
    </div>
    {message && <p className={message.ok ? 'ch-shop-ok' : 'ch-shop-error'} role={message.ok ? 'status' : 'alert'}>{message.text}</p>}
    <ShopState resource={catalog}>{data && <>
      {!data.purchases_enabled && <p className="ch-community-note" role="status">Podgląd sklepu: zakupy nie są jeszcze włączone. Ceny mogą się zmienić przed uruchomieniem.</p>}
      {data.items.length ? <div className="ch-shop-grid">{data.items.map(p => <ProductCard key={p.id} product={p} images={images}>
        <p className="ch-shop-price">{number(p.price_cc)} CC</p>
        {p.owned ? <span className="ch-shop-owned">Posiadasz</span>
          : !user ? <small>Zaloguj się, aby kupić.</small>
          : data.purchases_enabled && <button disabled={!!busy || !!pending || (balance != null && BigInt(balance) < BigInt(p.price_cc))} onClick={() => buy(p)}>{busy === String(p.id) ? 'Kupowanie…' : balance != null && BigInt(balance) < BigInt(p.price_cc) ? 'Za mało CC' : 'Kup'}</button>}
      </ProductCard>)}</div> : <div className="ch-community-state"><h2>Brak przedmiotów</h2><p>Żaden przedmiot nie pasuje do filtrów.</p></div>}
      <div className="ch-community-pager"><button disabled={page <= 1} onClick={() => update({ page:page - 1 })}>Poprzednia</button><span>Strona {page} · {data.total} przedmiotów</span><button disabled={page * data.page_size >= data.total} onClick={() => update({ page:page + 1 })}>Następna</button></div>
    </>}</ShopState>
  </>
}
export function InventoryPage() {
  const images = useProductImages()
  const [before, setBefore] = useState(null)
  const inventory = useCommunityResource('shop.php?action=inventory')
  const history = useCommunityResource('shop.php?action=purchases' + (before ? '&before=' + encodeURIComponent(before) : ''))
  return <>
    <div className="ch-community-heading"><p className="ch-eyebrow">CLOUDHUB / INVENTORY</p><h1>Ekwipunek<span>.</span></h1><p>Przedmioty odblokowane na stałe. Ustawienia float, pattern, naklejek, zawieszek, StatTrak i nametagu zmieniasz bez opłat w konfiguratorze.</p></div>
    <ShopState resource={inventory}>{inventory.data && (inventory.data.items.length
      ? <div className="ch-shop-grid">{inventory.data.items.map(p => <ProductCard key={p.id} product={p} images={images}><small>Od {date(p.acquired_at)}</small><Link className="ch-shop-configure" to="/team">Personalizuj za 0 CC</Link></ProductCard>)}</div>
      : <div className="ch-community-state"><h2>Ekwipunek jest pusty</h2><p>Kup pierwszy przedmiot w <Link to="/shop">sklepie</Link>.</p></div>)}</ShopState>
    <section className="ch-community-card"><h2>Historia zakupów</h2><ShopState resource={history}>{history.data && <>{history.data.items.length ? history.data.items.map(item => <article className="ch-ledger-row ch-shop-history" key={item.id}><div><strong>{item.product.name}</strong><small>{date(item.created_at)} · zakup #{item.id} · transakcja #{item.transaction_id}</small></div><strong className="ch-amount-debit">−{number(item.price_cc)} CC</strong></article>) : <p>Nie masz jeszcze zakupów.</p>}
      <div className="ch-community-pager"><button disabled={!before} onClick={() => setBefore(null)}>Najnowsze</button><button disabled={!history.data.next_before} onClick={() => setBefore(history.data.next_before)}>Starsze</button></div></>}</ShopState></section>
  </>
}
function ProductEditor({ product, onSaved }) {
  const [price, setPrice] = useState(String(product.price_cc)), [active, setActive] = useState(product.is_active), [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false), [error, setError] = useState('')
  // One ID per attempt; a rejected change gets a new ID so corrected values are not treated as a reuse.
  const [request, setRequest] = useState(() => crypto.randomUUID())
  const submit = async e => {
    e.preventDefault(); setBusy(true); setError('')
    try { await communityRequest('admin.php?action=shop_product', { product_id:String(product.id), price_cc:price, is_active:active ? '1' : '0', reason, request_id:request }); onSaved() }
    catch (err) { setError(err.message); if (err.status >= 400 && err.status < 500) setRequest(crypto.randomUUID()) } finally { setBusy(false) }
  }
  return <form className="ch-shop-editor" onSubmit={submit}>
    <label>Cena (CC)<input required type="number" min="1" max="1000000" step="1" value={price} onChange={e => setPrice(e.target.value)}/></label>
    <label className="ch-shop-check"><input type="checkbox" checked={active} onChange={e => setActive(e.target.checked)}/> Aktywny w sklepie</label>
    <label>Powód zmiany<input required maxLength={500} value={reason} onChange={e => setReason(e.target.value)}/></label>
    <button disabled={busy} type="submit">{busy ? 'Zapisywanie…' : 'Zapisz'}</button>{error && <p role="alert">{error}</p>}
  </form>
}
export function ShopAdmin() {
  const [search, setSearch] = useState(''), [query, setQuery] = useState({ search:'', kind:'all', active:'' }), [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0), [editing, setEditing] = useState(null)
  const products = useCommunityResource('admin.php?' + new URLSearchParams({ action:'shop_products', ...query, page:String(page), sort:'name' }), revision, { keep:true })
  const stats = useCommunityResource('admin.php?action=shop_stats', revision, { keep:true })
  if (products.error?.status === 404) return null
  const s = stats.data
  return <section className="ch-community-card"><h2>Sklep i ekonomia</h2>
    {s && <div className="ch-config-grid">{[['CC w obiegu',number(s.circulation_cc)],['Zakupy',number(s.purchases)],['Wydane CC',number(s.spent_cc)],['Aktywne produkty',`${number(s.active_products)} / ${number(s.products)}`],['CC z rund (7 dni)',number(s.round_cc_7d)],['Średnio na gracza (7 dni)',s.earning_players_7d ? number(String(BigInt(s.round_cc_7d) / BigInt(s.earning_players_7d))) : '—']].map(([l,v]) => <p key={l}><strong>{v}</strong> {l}</p>)}</div>}
    {s && !s.purchases_enabled && <p>Zakupy są wyłączone w konfiguracji serwera. Ceny są propozycją do zatwierdzenia.</p>}
    <form className="ch-community-search" onSubmit={e => { e.preventDefault(); setQuery(q => ({ ...q, search })); setPage(1) }}>
      <label htmlFor="shop-admin-search">Produkt</label><input id="shop-admin-search" value={search} onChange={e => setSearch(e.target.value)} maxLength={64}/>
      <select aria-label="Kategoria" value={query.kind} onChange={e => { setQuery(q => ({ ...q, kind:e.target.value })); setPage(1) }}>{kinds.map(([v,l]) => <option key={v} value={v}>{l}</option>)}</select>
      <select aria-label="Status" value={query.active} onChange={e => { setQuery(q => ({ ...q, active:e.target.value })); setPage(1) }}><option value="">Wszystkie</option><option value="1">Aktywne</option><option value="0">Nieaktywne</option></select>
      <button>Szukaj produktu</button>
    </form>
    <ShopState resource={products}>{products.data && <><div className="ch-community-table-wrap"><table className="ch-community-table"><thead><tr><th>Produkt</th><th>Kategoria</th><th>Cena</th><th>Status</th><th>Właściciele</th><th></th></tr></thead><tbody>
      {products.data.items.map(p => <tr key={p.id}><td>{p.name}</td><td>{kindLabel[p.kind]}</td><td>{number(p.price_cc)} CC</td><td>{p.is_active ? 'Aktywny' : 'Nieaktywny'}</td><td>{number(p.owners)}</td><td>{editing === p.id ? <ProductEditor product={p} onSaved={() => { setEditing(null); setRevision(v => v + 1) }}/> : <button onClick={() => setEditing(p.id)}>Edytuj</button>}</td></tr>)}
    </tbody></table></div>
    <div className="ch-community-pager"><button disabled={page <= 1} onClick={() => setPage(page - 1)}>Poprzednia</button><span>Strona {page} · {products.data.total} produktów</span><button disabled={page * products.data.page_size >= products.data.total} onClick={() => setPage(page + 1)}>Następna</button></div></>}</ShopState>
  </section>
}
