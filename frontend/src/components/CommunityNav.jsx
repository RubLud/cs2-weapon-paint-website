import { NotificationLink } from './Social'
import { NavLink } from 'react-router-dom'
import { useCommunityResource, useSocialCapabilities } from '../lib/community'
import '../styles/Community.css'
export default function CommunityNav({ user }) {
  const { data } = useCommunityResource(user ? 'cloudhub.php' : null)
  const social=useSocialCapabilities(user)
  return <nav className="ch-community-nav" aria-label="Społeczność CloudHub">
    <NavLink to="/ranking">Ranking</NavLink>
    <NavLink to="/players">Profile</NavLink>
    {user && <><NavLink to={`/players/${user.steamid}`}>Mój profil</NavLink><NavLink to="/wallet">Portfel</NavLink></>}
    {data?.features?.shop && <><NavLink to="/shop">Sklep</NavLink><NavLink to="/inventory">Ekwipunek</NavLink></>}
    {social && <><NavLink to="/friends">Znajomi</NavLink><NotificationLink user={user}/>{social.moderate&&<NavLink to="/moderation">Moderacja</NavLink>}</>}
    {data?.account.roles.includes('OWNER') && <NavLink to="/admin">Administracja</NavLink>}
  </nav>
}
