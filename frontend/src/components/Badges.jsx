const number = value => new Intl.NumberFormat('pl-PL').format(BigInt(value))
/** Role, rank and TOP badges shared by rankings, profiles and comment authors. */
export default function Badges({ stats }) {
  if (!stats) return null
  return <div className="ch-badges">{stats.roles?.map(role => <span className={`ch-badge ch-badge--${role.toLowerCase()}`} key={role}>{role === 'OWNER' ? '👑 OWNER' : role}</span>)}
    {stats.rank && <span className={`ch-badge ch-rank--${stats.rank.rank_key}`}>{stats.rank.rank_key === 'diamond' ? '💎 ' : '◆ '}{stats.rank.name}</span>}
    {stats.position && <span className="ch-badge ch-badge--top">🏆 TOP {number(stats.position)}</span>}
  </div>
}
