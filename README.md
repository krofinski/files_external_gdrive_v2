## Based on the original work by Samy Nastuzzi, completely rewritten and optimized for Nextcloud 34 by el-choco.

# Files External Google Drive (Nextcloud App)

Native Google Drive external storage support for Nextcloud. Fully refactored and optimized for Nextcloud 33 & 34.

## 🚀 Key Features & Updates

*   **Nextcloud 34 Ready:** Includes a custom `DirWrapper` implementation to seamlessly replace the deprecated and removed `OC\Files\Stream\Dir` class.
*   **Automated OAuth2 Flow:** No more manual token copying! Generates and injects the token directly within the Nextcloud UI.
*   **Strict CSP & COOP Bypass:** Fully compatible with modern browser security policies (like Microsoft Edge) and Nextcloud's strict Content Security Policy.

## 🛠️ Installation

First of all, you need to enable the official `files_external` app in Nextcloud.

### From Git
*   Clone the repository into your custom apps folder:
    `git clone https://github.com/el-choco/files_external_gdrive_v2.git`
*   Set the correct permissions (e.g., `chown -R 33:33 files_external_gdrive_v2`).
*   Enable the app via OCC:
    `php occ app:enable files_external_gdrive_v2`

## ⚙️ Usage

### Google Configuration
In order to use the app, you need to create an OAuth2 client via the [Google Cloud Console](https://console.developers.google.com/apis/).

Complete all needed information:
*   **App name:** (e.g., "Nextcloud" so you remember what it is used for)
*   **Authorized JavaScript origins:** Your Nextcloud domain.
*   **Authorized redirect URIs:** Important! Must exactly match your Nextcloud callback URL (e.g., `https://your-domain.com/apps/files_external_gdrive_v2/callback`).

### On Nextcloud
1.  Go to **Admin Settings -> External Storage**.
2.  Select **Google Drive** from the dropdown menu.
3.  Enter your Google **Client ID** and **Client Secret**.
4.  Click the new **"🔑 Connect with Google & Get Token"** button.
5.  Authorize the app in the Google popup window.
6.  Once the popup closes automatically and the button turns green (**"✅ Success! Please click Create!"**), click the checkmark to save and mount your drive.