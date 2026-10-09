/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import Auth from "./Auth";
export default function VerifyEmail(props: any) {
    return <Auth {...props} mode="verify" />;
}
