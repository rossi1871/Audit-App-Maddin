package app.auditmaddin;

import android.Manifest;
import android.app.Activity;
import android.content.ContentValues;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.view.WindowManager;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;

/**
 * Schlanke Android-Hülle um die Web-App (audit-maddin.netlify.app).
 * Lädt immer die aktuelle Version von Netlify. Fragt Standort, Mikrofon und Kamera
 * direkt bei Android an und reicht die Freigaben an die Web-App weiter.
 */
public class MainActivity extends Activity {
    private static final String START_URL = "https://audit-maddin.netlify.app/";
    private static final String HOST = "audit-maddin.netlify.app";
    private static final int REQ_GEO = 1, REQ_MEDIA = 2, REQ_FILE = 3, REQ_CAM = 4;

    private WebView web;
    private GeolocationPermissions.Callback geoCb;
    private String geoOrigin;
    private PermissionRequest mediaReq;
    private ValueCallback<Uri[]> fileCb;
    private Uri camUri;
    private WebChromeClient.FileChooserParams pendingParams;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        web = new WebView(this);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setGeolocationEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setUserAgentString(s.getUserAgentString() + " AuditAppAndroid/1");

        web.addJavascriptInterface(new Bridge(), "AndroidBridge");

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                Uri u = r.getUrl();
                if (HOST.equals(u.getHost())) return false;
                // Fremde Links (Anbieter-Seiten, Kosten-Dashboard) im Browser öffnen
                try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception e) { /* kein Browser */ }
                return true;
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback cb) {
                if (has(Manifest.permission.ACCESS_FINE_LOCATION) || has(Manifest.permission.ACCESS_COARSE_LOCATION)) {
                    cb.invoke(origin, true, false);
                } else {
                    geoCb = cb; geoOrigin = origin;
                    requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_GEO);
                }
            }

            @Override
            public void onPermissionRequest(PermissionRequest req) {
                List<String> need = new ArrayList<>();
                for (String r : req.getResources()) {
                    if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r) && !has(Manifest.permission.RECORD_AUDIO)) need.add(Manifest.permission.RECORD_AUDIO);
                    if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r) && !has(Manifest.permission.CAMERA)) need.add(Manifest.permission.CAMERA);
                }
                if (need.isEmpty()) { runOnUiThread(() -> req.grant(req.getResources())); return; }
                mediaReq = req;
                requestPermissions(need.toArray(new String[0]), REQ_MEDIA);
            }

            @Override
            public boolean onShowFileChooser(WebView v, ValueCallback<Uri[]> cb, FileChooserParams p) {
                if (fileCb != null) fileCb.onReceiveValue(null);
                fileCb = cb; pendingParams = p;
                boolean image = false;
                for (String t : p.getAcceptTypes()) if (t != null && t.startsWith("image")) image = true;
                if (image && !has(Manifest.permission.CAMERA)) {
                    requestPermissions(new String[]{Manifest.permission.CAMERA}, REQ_CAM);
                } else {
                    openChooser(image);
                }
                return true;
            }
        });

        if (b != null) web.restoreState(b); else web.loadUrl(START_URL);
    }

    private boolean has(String perm) {
        return checkSelfPermission(perm) == PackageManager.PERMISSION_GRANTED;
    }

    private void openChooser(boolean image) {
        try {
            Intent pick = pendingParams != null ? pendingParams.createIntent() : new Intent(Intent.ACTION_GET_CONTENT).setType("*/*");
            pick.addCategory(Intent.CATEGORY_OPENABLE);
            Intent chooser = Intent.createChooser(pick, image ? "Foto aufnehmen oder auswählen" : "Datei auswählen");
            camUri = null;
            if (image && has(Manifest.permission.CAMERA)) {
                ContentValues cv = new ContentValues();
                cv.put(MediaStore.Images.Media.DISPLAY_NAME, "audit_" + System.currentTimeMillis() + ".jpg");
                cv.put(MediaStore.Images.Media.MIME_TYPE, "image/jpeg");
                cv.put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/Audit");
                camUri = getContentResolver().insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, cv);
                Intent cam = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
                cam.putExtra(MediaStore.EXTRA_OUTPUT, camUri);
                cam.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
                chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{cam});
            }
            startActivityForResult(chooser, REQ_FILE);
        } catch (Exception e) {
            if (fileCb != null) fileCb.onReceiveValue(null);
            fileCb = null;
            Toast.makeText(this, "Auswahl konnte nicht geöffnet werden.", Toast.LENGTH_LONG).show();
        }
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        super.onActivityResult(req, res, data);
        if (req != REQ_FILE || fileCb == null) return;
        Uri[] out = null;
        if (res == RESULT_OK) {
            if (data != null && (data.getData() != null || data.getClipData() != null)) {
                out = WebChromeClient.FileChooserParams.parseResult(res, data);
            } else if (camUri != null) {
                out = new Uri[]{camUri};
            }
        }
        if (out == null && camUri != null) {
            try { getContentResolver().delete(camUri, null, null); } catch (Exception e) { /* egal */ }
        }
        fileCb.onReceiveValue(out);
        fileCb = null;
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] res) {
        boolean ok = res.length > 0;
        for (int r : res) if (r != PackageManager.PERMISSION_GRANTED) ok = false;
        if (req == REQ_GEO && geoCb != null) {
            boolean any = false;
            for (int r : res) if (r == PackageManager.PERMISSION_GRANTED) any = true;
            geoCb.invoke(geoOrigin, any, false);
            geoCb = null;
        } else if (req == REQ_MEDIA && mediaReq != null) {
            final PermissionRequest m = mediaReq;
            mediaReq = null;
            if (ok) runOnUiThread(() -> m.grant(m.getResources())); else runOnUiThread(m::deny);
        } else if (req == REQ_CAM) {
            openChooser(true);
        }
    }

    /** Dateien (Excel, Word, Sicherung, GeoJSON) in den Ordner «Downloads» speichern. */
    private class Bridge {
        @JavascriptInterface
        public boolean save(String name, String base64, String mime) {
            try {
                byte[] bytes = Base64.decode(base64, Base64.DEFAULT);
                ContentValues cv = new ContentValues();
                cv.put(MediaStore.Downloads.DISPLAY_NAME, name);
                cv.put(MediaStore.Downloads.MIME_TYPE, mime == null || mime.isEmpty() ? "application/octet-stream" : mime);
                cv.put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS);
                Uri u = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, cv);
                if (u == null) return false;
                try (OutputStream os = getContentResolver().openOutputStream(u)) { os.write(bytes); }
                return true;
            } catch (Exception e) {
                return false;
            }
        }

        /** Automatische Sicherung: immer dieselbe Datei in «Downloads» überschreiben. */
        @JavascriptInterface
        public boolean saveOver(String name, String base64, String mime) {
            byte[] bytes;
            try { bytes = Base64.decode(base64, Base64.DEFAULT); } catch (Exception e) { return false; }
            android.content.SharedPreferences sp = getSharedPreferences("bak", MODE_PRIVATE);
            String known = sp.getString("uri_" + name, null);
            if (known != null) {
                try (OutputStream os = getContentResolver().openOutputStream(Uri.parse(known), "wt")) {
                    if (os != null) { os.write(bytes); return true; }
                } catch (Exception e) { /* Datei gelöscht oder nicht mehr erreichbar: neu anlegen */ }
            }
            try {
                ContentValues cv = new ContentValues();
                cv.put(MediaStore.Downloads.DISPLAY_NAME, name);
                cv.put(MediaStore.Downloads.MIME_TYPE, mime == null || mime.isEmpty() ? "application/json" : mime);
                cv.put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS);
                Uri u = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, cv);
                if (u == null) return false;
                try (OutputStream os = getContentResolver().openOutputStream(u)) { os.write(bytes); }
                sp.edit().putString("uri_" + name, u.toString()).apply();
                return true;
            } catch (Exception e) {
                return false;
            }
        }

        /** Bericht teilen: Dateien in «Downloads/Audit» ablegen und an Mail, Signal, Teams usw. übergeben. */
        @JavascriptInterface
        public boolean shareFiles(String json) {
            try {
                org.json.JSONObject o = new org.json.JSONObject(json);
                org.json.JSONArray fs = o.optJSONArray("files");
                ArrayList<Uri> uris = new ArrayList<>();
                if (fs != null) {
                    for (int i = 0; i < fs.length(); i++) {
                        org.json.JSONObject f = fs.getJSONObject(i);
                        byte[] bytes = Base64.decode(f.getString("b64"), Base64.DEFAULT);
                        ContentValues cv = new ContentValues();
                        cv.put(MediaStore.Downloads.DISPLAY_NAME, f.getString("name"));
                        cv.put(MediaStore.Downloads.MIME_TYPE, f.optString("mime", "application/octet-stream"));
                        cv.put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/Audit");
                        Uri u = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, cv);
                        if (u == null) continue;
                        try (OutputStream os = getContentResolver().openOutputStream(u)) { os.write(bytes); }
                        uris.add(u);
                    }
                }
                Intent send;
                if (uris.size() > 1) {
                    send = new Intent(Intent.ACTION_SEND_MULTIPLE);
                    send.putParcelableArrayListExtra(Intent.EXTRA_STREAM, uris);
                    send.setType("*/*");
                } else if (uris.size() == 1) {
                    send = new Intent(Intent.ACTION_SEND);
                    send.putExtra(Intent.EXTRA_STREAM, uris.get(0));
                    send.setType(fs.getJSONObject(0).optString("mime", "*/*"));
                } else {
                    send = new Intent(Intent.ACTION_SEND);
                    send.setType("text/plain");
                }
                send.putExtra(Intent.EXTRA_SUBJECT, o.optString("subject", ""));
                send.putExtra(Intent.EXTRA_TEXT, o.optString("text", ""));
                send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                final Intent chooser = Intent.createChooser(send, "Bericht senden");
                chooser.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                runOnUiThread(() -> startActivity(chooser));
                return true;
            } catch (Exception e) {
                return false;
            }
        }

        /** Links wie mailto: öffnen; false, wenn keine passende App installiert ist. */
        @JavascriptInterface
        public boolean openUrl(String url) {
            try {
                Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                if (i.resolveActivity(getPackageManager()) == null) return false;
                runOnUiThread(() -> startActivity(i));
                return true;
            } catch (Exception e) {
                return false;
            }
        }

        @JavascriptInterface
        public boolean copyText(String text) {
            try {
                android.content.ClipboardManager cm = (android.content.ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
                cm.setPrimaryClip(android.content.ClipData.newPlainText("Audit", text));
                return true;
            } catch (Exception e) {
                return false;
            }
        }

        @JavascriptInterface
        public void keepScreenOn(boolean on) {
            runOnUiThread(() -> {
                if (on) getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
                else getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            });
        }
    }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) web.goBack(); else super.onBackPressed();
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }
}
