import { NavLink } from 'react-router-dom'
import { useCommunityResource } from '../lib/community'
import '../styles/Community.css'
export default function CommunityNav({ user }) {
  const { data } = useCommunityResource(user ? 'cloudhub.php' : null)
  return <nav className="ch-community-nav" aria-label="Społeczność CloudHub">
    <NavLink to="/ranking">Ranking</NavLink>
    <NavLink to="/players">Profile</NavLink>
    {user && <><NavLink to={`/players/${user.steamid}`}>Mój profil</NavLink><NavLink to="/wallet">Portfel</NavLink></>}
    {data?.account.roles.includes('OWNER') && <NavLink to="/admin">Administracja</NavLink>}
  </nav>
}
