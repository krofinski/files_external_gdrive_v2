document.addEventListener('DOMContentLoaded', function() {
    const observer = new MutationObserver(function() {
        if (document.getElementById('gdrive-oauth-btn')) return;

        const inputs = Array.from(document.querySelectorAll('input'));
        let clientIdInput = null;
        let clientSecretInput = null;
        let tokenInput = null;

        inputs.forEach(input => {
            const text = (input.placeholder || '') + (input.parentElement ? input.parentElement.innerText : '');
            const param = input.dataset.parameter || input.getAttribute('data-parameter') || '';
            if (text.includes('Client ID') || text.includes('ID de cliente') || param.includes('client_id')) clientIdInput = input;
            if (text.includes('Client Secret') || text.includes('Secreto de cliente') || param.includes('client_secret')) clientSecretInput = input;
            if (text.includes('Token') || param.includes('token')) tokenInput = input;
        });

        if (clientIdInput && clientSecretInput && tokenInput) {
            const btn = document.createElement('button');
            btn.id = 'gdrive-oauth-btn';
            btn.textContent = t('files_external_gdrive_v2', '🔑 Connect with Google & Get Token');
            btn.className = 'button primary';
            btn.style.marginTop = '15px';
            btn.style.width = '100%';
            btn.style.padding = '10px';
            btn.style.fontWeight = 'bold';
            
            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                if (!clientIdInput.value || !clientSecretInput.value) {
                    alert(t('files_external_gdrive_v2', 'Please enter Client ID and Secret first!'));
                    return;
                }
                
                btn.textContent = t('files_external_gdrive_v2', '⌛ Waiting for Google...');
                const redirectUri = window.location.origin + OC.generateUrl('/apps/files_external_gdrive_v2/callback');
                
                localStorage.removeItem('gdrive_oauth_code');
                
                const popup = window.open('about:blank', 'GoogleAuth', 'width=600,height=600');
                
                try {
                    const res = await fetch(OC.generateUrl('/apps/files_external_gdrive_v2/oauth'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                        body: JSON.stringify({ client_id: clientIdInput.value, step: 1, redirect: redirectUri })
                    });

                    if (!res.ok) {
                        const errText = await res.text();
                        console.error("Server Error on initial fetch:", errText);
                        popup.close();
                        alert(t('files_external_gdrive_v2', 'Server Error: 404 or 500. Check F12 Console!'));
                        btn.textContent = t('files_external_gdrive_v2', '❌ Error');
                        return;
                    }

                    const data = await res.json();
                    
                    if (data.status === 'success') {
                        popup.location.href = data.data.url; 
                        let tokenFetched = false;
                        
                        const pollTimer = setInterval(async function() {
                            const storedCode = localStorage.getItem('gdrive_oauth_code');
                            
                            if (storedCode && !tokenFetched) {
                                clearInterval(pollTimer);
                                tokenFetched = true;
                                localStorage.removeItem('gdrive_oauth_code');
                                
                                if (popup && !popup.closed) popup.close();
                                
                                btn.textContent = t('files_external_gdrive_v2', '🔄 Generating token...');
                                
                                try {
                                    const tokenReq = await fetch(OC.generateUrl('/apps/files_external_gdrive_v2/oauth'), {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                                        body: JSON.stringify({ client_id: clientIdInput.value, client_secret: clientSecretInput.value, step: 2, code: storedCode, redirect: redirectUri })
                                    });
                                    const tokenRes = await tokenReq.json();
                                    
                                    if (tokenRes.status === 'success') {
                                        const nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value").set;
                                        nativeInputValueSetter.call(tokenInput, tokenRes.data.token);
                                        tokenInput.dispatchEvent(new Event('input', { bubbles: true }));
                                        tokenInput.dispatchEvent(new Event('change', { bubbles: true }));
                                        tokenInput.dispatchEvent(new FocusEvent('blur', { bubbles: true }));
                                        
                                        btn.textContent = t('files_external_gdrive_v2', '✅ Success! Please click Create!');
                                        btn.style.backgroundColor = '#4caf50';
                                        btn.style.color = 'white';
                                    } else {
                                        alert(t('files_external_gdrive_v2', 'Error: Google denied the token!'));
                                        btn.textContent = t('files_external_gdrive_v2', '❌ Error');
                                    }
                                } catch(err) {
                                    console.error("Error fetching token:", err);
                                    btn.textContent = t('files_external_gdrive_v2', '❌ Error');
                                }
                            }
                            
                            if (popup && popup.closed && !tokenFetched) {
                                clearInterval(pollTimer);
                                if (btn.textContent === t('files_external_gdrive_v2', '⌛ Waiting for Google...')) {
                                    btn.textContent = t('files_external_gdrive_v2', '❌ Cancelled. Try again?');
                                }
                            }
                        }, 500);
                    } else {
                        popup.close();
                        alert(t('files_external_gdrive_v2', 'Unknown Server Error'));
                    }
                } catch (err) {
                    console.error("Network Error:", err);
                    popup.close();
                    btn.textContent = t('files_external_gdrive_v2', '❌ Network Error');
                }
            });
            tokenInput.parentElement.appendChild(btn);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
});