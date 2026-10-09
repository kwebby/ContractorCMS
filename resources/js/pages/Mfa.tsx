/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import Auth from "./Auth";
export default function Mfa(props: any) {
    return <Auth {...props} mode="mfa" />;
}
