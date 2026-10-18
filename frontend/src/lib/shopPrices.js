import { useEffect, useState } from 'react'
import { communityRequest } from './community'

let pending = null
/** One price/ownership index per page load; a disabled shop (404) or any error resolves to null. `enforced`: saving needs ownership (Stage 6). */
function loadShopPrices() {
  pending ||= communityRequest('shop.php?action=prices')
    .then(data => Object.assign(new Map(data.items.map(([defindex, paint, price, owned]) => [`${defindex}:${paint}`, { price, owned }])), { enforced: data.enforced === true }))
    .catch(() => null)
  return pending
}
/** Call after a purchase so the configurator shows the new ownership. */
export function invalidateShopPrices() { pending = null }
export function useShopPrices() {
  const [prices, setPrices] = useState(null)
  useEffect(() => {
    let live = true
    loadShopPrices().then(map => { if (live) setPrices(map) })
    return () => { live = false }
  }, [])
  return prices
}
