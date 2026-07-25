import streamlit as st
import pandas as pd
import numpy as np
import io
import plotly.express as px
import plotly.graph_objects as go

# ------------------------------
# Konfigurasi Halaman
# ------------------------------
st.set_page_config(page_title="SPK Laptop SAW", layout="wide", initial_sidebar_state="expanded")

st.markdown("""
    <style>
        .main-header { font-size: 2.5rem; color: #4CAF50; }
        .sub-header { font-size: 1.2rem; color: #555; }
        .highlight { background-color: #f0f2f6; padding: 10px; border-radius: 5px; }
    </style>
""", unsafe_allow_html=True)

st.markdown("<h1 class='main-header'>SPK Pemilihan Laptop dengan SAW</h1>", unsafe_allow_html=True)
st.markdown("<p class='sub-header'>Metode Simple Additive Weighting – Dataset laptop_prices.csv</p>", unsafe_allow_html=True)

# ------------------------------
# Inisialisasi Session State
# ------------------------------
if 'df_raw' not in st.session_state:
    st.session_state.df_raw = None
if 'df_clean' not in st.session_state:
    st.session_state.df_clean = None
if 'selected_criteria' not in st.session_state:
    st.session_state.selected_criteria = []
if 'weights' not in st.session_state:
    st.session_state.weights = {}
if 'profile' not in st.session_state:
    st.session_state.profile = "Custom"

# ====================================================================
# FUNGSI FORMAT HARGA (USD)
# ====================================================================
def format_price_usd(val):
    if pd.isna(val):
        return ""
    return f"${int(val):,}"

# ====================================================================
# FUNGSI SKOR CPU & GPU
# ====================================================================
def get_cpu_score(cpu_str):
    """Mapping CPU ke skor performa (semakin tinggi semakin baik)"""
    cpu_str = str(cpu_str).lower()
    if 'm4 max' in cpu_str:
        return 8
    elif 'm4 pro' in cpu_str:
        return 7
    elif 'm4' in cpu_str:
        return 6
    elif 'ryzen 9' in cpu_str:
        return 6
    elif 'ryzen 7' in cpu_str or 'ryzen ai 7' in cpu_str:
        return 5
    elif 'ryzen 5' in cpu_str or 'ryzen ai 5' in cpu_str:
        return 4
    elif 'core ultra 9' in cpu_str:
        return 6
    elif 'core ultra 7' in cpu_str:
        return 5
    elif 'core ultra 5' in cpu_str:
        return 4
    elif 'core i7' in cpu_str:
        return 4
    elif 'core i5' in cpu_str:
        return 3
    elif 'snapdragon x' in cpu_str:
        return 4
    elif 'intel n100' in cpu_str:
        return 1
    elif 'mediatek' in cpu_str:
        return 1
    else:
        return 2

def get_gpu_score(gpu_str):
    """Mapping GPU ke skor performa (semakin tinggi semakin baik)"""
    gpu_str = str(gpu_str).lower()
    if 'rtx 5080' in gpu_str:
        return 6
    elif 'rtx 5070' in gpu_str:
        return 5
    elif 'rtx 5060' in gpu_str:
        return 4
    elif 'rtx 5050' in gpu_str:
        return 3
    elif 'rtx 4050' in gpu_str:
        return 2
    elif 'integrated' in gpu_str:
        return 0
    else:
        return 0

# ====================================================================
# FUNGSI PREPROCESS
# ====================================================================
def preprocess_data(df):
    df_clean = df.copy()

    # Normalisasi nama kolom
    df_clean.columns = (
        df_clean.columns
        .str.strip()
        .str.lower()
        .str.replace(' ', '_')
        .str.replace(r'[^\w_]', '', regex=True)
    )

    rename_map = {
        'brand': 'brand_name',
        'model': 'model',
        'price_usd': 'price',
        'ram_gb': 'ram_num',
        'storage_gb': 'memory_size',
        'cpu': 'cpu_str',
        'gpu': 'gpu_str',
        'category': 'category'
    }
    for old, new in rename_map.items():
        if old in df_clean.columns:
            df_clean.rename(columns={old: new}, inplace=True)

    required_cols = ['brand_name', 'model', 'price', 'ram_num', 'memory_size', 'cpu_str', 'gpu_str']
    missing = [c for c in required_cols if c not in df_clean.columns]
    if missing:
        st.error(f"Kolom wajib tidak ditemukan: {missing}")
        st.write("Kolom yang tersedia di dataset:", list(df_clean.columns))
        st.write("Cuplikan data:", df_clean.head(2))
        return None

    df_clean['Nama Laptop'] = df_clean['brand_name'].astype(str) + " " + df_clean['model'].astype(str)
    df_clean = df_clean.drop_duplicates(subset=['Nama Laptop'])
    df_clean = df_clean.dropna(subset=['price', 'ram_num', 'memory_size', 'cpu_str', 'gpu_str'])

    for col in ['price', 'ram_num', 'memory_size']:
        if col in df_clean.columns:
            df_clean[col] = pd.to_numeric(df_clean[col], errors='coerce')
    df_clean = df_clean.dropna(subset=['price', 'ram_num', 'memory_size'])

    df_clean['cpu_score'] = df_clean['cpu_str'].apply(get_cpu_score)
    df_clean['gpu_score'] = df_clean['gpu_str'].apply(get_gpu_score)

    return df_clean

# ====================================================================
# SIDEBAR – Upload & Data Info
# ====================================================================
with st.sidebar:
    st.header("Data dan Pengaturan")
    uploaded_file = st.file_uploader("Unggah dataset (CSV / Excel)", type=["csv", "xlsx"])

    if uploaded_file is not None:
        try:
            file_ext = uploaded_file.name.split('.')[-1].lower()
            raw_data = uploaded_file.read()

            if file_ext == 'csv':
                encodings = ['utf-8', 'latin1', 'cp1252', 'iso-8859-1']
                df_raw = None
                for enc in encodings:
                    try:
                        df_raw = pd.read_csv(io.BytesIO(raw_data), encoding=enc, sep=None, engine='python')
                        break
                    except UnicodeDecodeError:
                        continue
                    except Exception as e:
                        st.warning(f"Gagal baca dengan encoding {enc}: {e}")
                        continue
                if df_raw is None:
                    st.error("Tidak dapat membaca file CSV dengan encoding standar. Coba konversi file ke UTF-8.")
                    st.stop()
            else:
                df_raw = pd.read_excel(io.BytesIO(raw_data), engine='openpyxl')
                if len(df_raw.columns) == 1:
                    first_col = df_raw.columns[0]
                    sample = str(df_raw.iloc[0, 0])
                    if ',' in sample or ';' in sample or '\t' in sample:
                        csv_str = first_col + "\n" + "\n".join(df_raw.iloc[:,0].astype(str).tolist())
                        try:
                            df_raw = pd.read_csv(io.StringIO(csv_str), sep=None, engine='python')
                        except:
                            df_raw = pd.read_csv(io.StringIO(csv_str), delimiter=';', engine='python')

            df_raw.columns = df_raw.columns.str.strip().str.lower().str.replace(' ', '_').str.replace(r'[^\w_]', '', regex=True)

            st.session_state.df_raw = df_raw
            st.success(f"Dataset berhasil dimuat! {df_raw.shape[0]} baris, {df_raw.shape[1]} kolom")

            with st.expander("Info Dataset"):
                st.write("Kolom yang tersedia:")
                st.write(list(df_raw.columns))
                st.write("5 Data Pertama:")
                st.dataframe(df_raw.head())

        except Exception as e:
            st.error(f"Gagal membaca file: {e}")
            st.stop()
    else:
        st.info("Silakan unggah file dataset untuk memulai.")
        st.stop()

# ------------------------------
# PREPROCESS DATA
# ------------------------------
df_clean = preprocess_data(st.session_state.df_raw)
if df_clean is None:
    st.stop()
st.session_state.df_clean = df_clean

# ------------------------------
# SIDEBAR – Filter & Selection
# ------------------------------
with st.sidebar:
    st.header("Filter Data")
    
    st.subheader("Rentang Harga (USD)")
    min_price = int(df_clean['price'].min())
    max_price = int(df_clean['price'].max())
    
    if min_price == max_price:
        price_range = st.slider(
            "Harga (USD)", 
            min_value=min_price, 
            max_value=max_price + 1,
            value=(min_price, max_price + 1),
            step=10,
            format="$%d"
        )
    else:
        default_min = max(min_price, 200)
        default_max = min(max_price, 2000)
        if default_min >= default_max:
            default_min = min_price
            default_max = max_price
        price_range = st.slider(
            "Harga (USD)", 
            min_value=min_price, 
            max_value=max_price, 
            value=(default_min, default_max),
            step=10,
            format="$%d"
        )
    
    st.caption(f"Rentang harga: {format_price_usd(price_range[0])} – {format_price_usd(price_range[1])}")
    
    brands = sorted(df_clean['brand_name'].unique())
    selected_brands = st.multiselect("Pilih Merek (kosongkan = semua)", brands, default=[])
    
    df_filtered = df_clean[
        (df_clean['price'] >= price_range[0]) & 
        (df_clean['price'] <= price_range[1])
    ]
    if selected_brands:
        df_filtered = df_filtered[df_filtered['brand_name'].isin(selected_brands)]
    
    if len(df_filtered) == 0:
        st.warning("Tidak ada laptop yang cocok dengan filter harga dan merek. Silakan ubah rentang harga.")
        df_filtered = df_clean.head(1)
        st.info("Tampilkan data dummy, silakan ubah filter.")
    
    total_rows = len(df_filtered)
    min_limit = 1
    max_limit = min(10, total_rows) if total_rows > 0 else 1
    default_value = min(10, total_rows) if total_rows > 0 else 1
    rows_limit = st.slider(
        "Jumlah Laptop yang Diproses (maks. 10)", 
        min_value=min_limit, 
        max_value=max_limit, 
        value=default_value,
        step=1
    )
    df_filtered = df_filtered.head(rows_limit)

    st.caption(f"Menampilkan {len(df_filtered)} laptop dari {len(df_clean)} total (setelah filter harga dan merek).")

# ====================================================================
# TAB UTAMA
# ====================================================================
tab1, tab2, tab3, tab4 = st.tabs(["Eksplorasi Data", "Kriteria dan Bobot", "Perhitungan SAW", "Peringkat"])

# ====================================================================
# TAB 1: EKSPLORASI DATA
# ====================================================================
with tab1:
    st.header("Eksplorasi Data Laptop")

    col1, col2 = st.columns(2)
    with col1:
        st.metric("Jumlah Laptop", len(df_filtered))
        st.metric("Rentang Harga", f"{format_price_usd(df_filtered['price'].min())} – {format_price_usd(df_filtered['price'].max())}")
    with col2:
        st.metric("Rata-rata RAM", f"{df_filtered['ram_num'].mean():.1f} GB")
        st.metric("Rata-rata Storage", f"{df_filtered['memory_size'].mean():.0f} GB")

    # Histogram harga
    fig_price = px.histogram(df_filtered, x='price', nbins=50, title='Distribusi Harga (USD)', labels={'price':'Harga (USD)'})
    fig_price.update_layout(xaxis_tickformat="$,.0f")
    st.plotly_chart(fig_price, use_container_width=True)

    # Scatter plot RAM vs Harga
    fig_scatter = px.scatter(df_filtered, x='ram_num', y='price', color='brand_name', hover_data=['Nama Laptop'],
                             title='RAM vs Harga', labels={'ram_num':'RAM (GB)', 'price':'Harga (USD)'})
    fig_scatter.update_layout(yaxis_tickformat="$,.0f")
    st.plotly_chart(fig_scatter, use_container_width=True)

    with st.expander("Lihat Data Detail"):
        display_df = df_filtered.copy()
        if 'price' in display_df.columns:
            display_df['price'] = display_df['price'].apply(format_price_usd)
        st.dataframe(display_df)

# ====================================================================
# TAB 2: KRITERIA & BOBOT
# ====================================================================
with tab2:
    st.header("Pilih Kriteria dan Atur Bobot")

    candidate_criteria = {
        'price': {'label': 'Harga (USD)', 'default_benefit': False},
        'ram_num': {'label': 'RAM (GB)', 'default_benefit': True},
        'memory_size': {'label': 'Storage (GB)', 'default_benefit': True},
        'cpu_score': {'label': 'Skor CPU (performanya)', 'default_benefit': True},
        'gpu_score': {'label': 'Skor GPU (performanya)', 'default_benefit': True},
    }

    available = {k: v for k, v in candidate_criteria.items() if k in df_filtered.columns and df_filtered[k].notna().any()}

    if not st.session_state.selected_criteria:
        default_selected = ['price', 'ram_num', 'memory_size', 'cpu_score', 'gpu_score']
        st.session_state.selected_criteria = [k for k in default_selected if k in available]

    selected = st.multiselect(
        "Pilih kriteria yang akan digunakan dalam perhitungan SAW",
        options=list(available.keys()),
        default=st.session_state.selected_criteria,
        format_func=lambda x: available[x]['label']
    )
    st.session_state.selected_criteria = selected

    if not selected:
        st.warning("Pilih minimal satu kriteria.")
        st.stop()

    st.subheader("Pengaturan Bobot dan Tipe Kriteria")

    cols = st.columns(len(selected))
    weights = {}
    for i, crit in enumerate(selected):
        with cols[i]:
            st.markdown(f"**{available[crit]['label']}**")
            is_benefit = st.checkbox("Benefit", value=available[crit]['default_benefit'], key=f"benefit_{crit}")
            w = st.number_input("Bobot", min_value=0.0, max_value=1.0, value=0.20, step=0.01, key=f"weight_{crit}")
            weights[crit] = {'weight': w, 'benefit': is_benefit}

    total_w = sum(v['weight'] for v in weights.values())
    if total_w == 0:
        st.error("Total bobot tidak boleh 0.")
        st.stop()
    for crit in weights:
        weights[crit]['weight'] /= total_w

    st.session_state.weights = weights

    st.write("Bobot setelah normalisasi (total = 1):")
    weight_df = pd.DataFrame([
        {'Kriteria': available[crit]['label'], 'Bobot': weights[crit]['weight'], 'Tipe': 'Benefit' if weights[crit]['benefit'] else 'Cost'}
        for crit in selected
    ])
    st.dataframe(weight_df)

    st.subheader("Gunakan Profil Bawaan")
    profile_options = {
        "Mahasiswa (Harga murah)": {'price':0.40, 'ram_num':0.20, 'memory_size':0.15, 'cpu_score':0.15, 'gpu_score':0.10},
        "Pekerja Kantoran (RAM dan Storage)": {'price':0.20, 'ram_num':0.30, 'memory_size':0.25, 'cpu_score':0.15, 'gpu_score':0.10},
        "Desainer/Gamer (Performa)": {'price':0.10, 'ram_num':0.20, 'memory_size':0.15, 'cpu_score':0.25, 'gpu_score':0.30},
        "Seimbang": {'price':0.20, 'ram_num':0.20, 'memory_size':0.20, 'cpu_score':0.20, 'gpu_score':0.20},
    }
    valid_profiles = {}
    for name, prof in profile_options.items():
        if all(k in selected for k in prof.keys()):
            valid_profiles[name] = prof
        else:
            prof_filled = {k: prof.get(k, 0.0) for k in selected}
            if sum(prof_filled.values()) > 0:
                valid_profiles[name] = prof_filled

    if valid_profiles:
        chosen_profile = st.selectbox("Pilih profil", list(valid_profiles.keys()))
        if st.button("Terapkan Bobot Profil"):
            prof_weights = valid_profiles[chosen_profile]
            total = sum(prof_weights.values())
            if total > 0:
                for crit in prof_weights:
                    prof_weights[crit] /= total
                for crit in selected:
                    weights[crit]['weight'] = prof_weights.get(crit, 0.0)
                st.session_state.weights = weights
                st.rerun()
    else:
        st.info("Tidak ada profil yang cocok dengan kriteria yang dipilih.")

# ====================================================================
# TAB 3: PERHITUNGAN SAW
# ====================================================================
with tab3:
    st.header("Matriks Normalisasi dan Perhitungan SAW")

    if not st.session_state.selected_criteria:
        st.warning("Belum ada kriteria yang dipilih.")
        st.stop()

    selected = st.session_state.selected_criteria
    weights = st.session_state.weights

    df_alt = df_filtered[['Nama Laptop'] + selected].copy()

    norm_df = df_alt.copy()
    for crit in selected:
        col = crit
        if weights[crit]['benefit']:
            max_val = df_alt[col].max()
            if max_val == 0:
                norm_df[f'Norm_{col}'] = 0
            else:
                norm_df[f'Norm_{col}'] = df_alt[col] / max_val
        else:
            min_val = df_alt[col].min()
            if min_val == 0:
                norm_df[f'Norm_{col}'] = 0
            else:
                norm_df[f'Norm_{col}'] = min_val / df_alt[col]

    score = pd.Series(0.0, index=df_alt.index)
    for crit in selected:
        score += norm_df[f'Norm_{crit}'] * weights[crit]['weight']
    norm_df['Skor SAW'] = score

    st.subheader("Matriks Normalisasi (R)")
    st.dataframe(norm_df)

    with st.expander("Formula Normalisasi"):
        st.latex(r"r_{ij} = \frac{x_{ij}}{\max(x_{ij})} \quad \text{(Benefit)}")
        st.latex(r"r_{ij} = \frac{\min(x_{ij})}{x_{ij}} \quad \text{(Cost)}")
        st.latex(r"V_i = \sum_{j=1}^{n} w_j \cdot r_{ij}")

    fig = px.bar(norm_df.sort_values('Skor SAW', ascending=False).head(20),
                 x='Nama Laptop', y='Skor SAW', title='20 Laptop dengan Skor Tertinggi',
                 labels={'Skor SAW':'Nilai Preferensi'})
    st.plotly_chart(fig, use_container_width=True)

# ====================================================================
# TAB 4: PERINGKAT AKHIR
# ====================================================================
with tab4:
    st.header("Peringkat Laptop Berdasarkan SAW")

    if not st.session_state.selected_criteria:
        st.warning("Silakan pilih kriteria di tab sebelumnya.")
        st.stop()

    selected = st.session_state.selected_criteria
    weights = st.session_state.weights

    df_alt = df_filtered[['Nama Laptop'] + selected].copy()

    norm_df = df_alt.copy()
    for crit in selected:
        col = crit
        if weights[crit]['benefit']:
            max_val = df_alt[col].max()
            norm_df[f'Norm_{col}'] = df_alt[col] / max_val if max_val != 0 else 0
        else:
            min_val = df_alt[col].min()
            norm_df[f'Norm_{col}'] = min_val / df_alt[col] if min_val != 0 else 0

    score = pd.Series(0.0, index=df_alt.index)
    for crit in selected:
        score += norm_df[f'Norm_{crit}'] * weights[crit]['weight']
    df_alt['Skor SAW'] = score

    ranking = df_alt.sort_values('Skor SAW', ascending=False).reset_index(drop=True)
    ranking.index = ranking.index + 1
    ranking.index.name = 'Peringkat'

    st.success(f"Laptop Terbaik untuk profil yang dipilih: {ranking.iloc[0]['Nama Laptop']} (Skor: {ranking.iloc[0]['Skor SAW']:.4f})")

    st.subheader("Daftar Peringkat")
    
    display_ranking = ranking.copy()
    if 'price' in display_ranking.columns:
        display_ranking['price'] = display_ranking['price'].apply(format_price_usd)
    
    column_config = {}
    for col in display_ranking.columns:
        if col == 'price':
            column_config[col] = st.column_config.TextColumn("Harga (USD)", help="Harga dalam Dolar AS")
        elif col == 'Nama Laptop':
            column_config[col] = st.column_config.TextColumn("Nama Laptop", width="large")
        elif col == 'Skor SAW':
            column_config[col] = st.column_config.NumberColumn("Skor SAW", format="%.4f")
        else:
            if display_ranking[col].dtype in ['float64', 'int64']:
                column_config[col] = st.column_config.NumberColumn(col.capitalize(), format="%.0f")
            else:
                column_config[col] = st.column_config.TextColumn(col.capitalize())
    
    st.dataframe(display_ranking, column_config=column_config, use_container_width=True)

    # Download Excel multi-sheet
    excel_ranking = ranking.copy()
    excel_norm = norm_df.copy()
    excel_alt = df_alt.copy()
    
    excel_alt_drop = excel_alt.drop(columns=['Skor SAW'], errors='ignore')
    excel_norm_drop = excel_norm.drop(columns=['Skor SAW'], errors='ignore')
    
    output = io.BytesIO()
    with pd.ExcelWriter(output, engine='openpyxl') as writer:
        excel_alt_drop.to_excel(writer, sheet_name='1. Matriks Keputusan', index=False)
        excel_norm_drop.to_excel(writer, sheet_name='2. Normalisasi (R)', index=False)
        excel_ranking.to_excel(writer, sheet_name='3. Hasil Peringkat Akhir', index=True)
    
    excel_data = output.getvalue()

    st.download_button(
        label="Unduh Perhitungan Lengkap (Format Excel)",
        data=excel_data,
        file_name="Perhitungan_SAW_Lengkap.xlsx",
        mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    )

    # Visualisasi
    top_n = st.slider("Tampilkan Top N", 5, len(ranking), 10)
    fig_top = px.bar(ranking.head(top_n), x='Nama Laptop', y='Skor SAW',
                     title=f'Top {top_n} Laptop', text='Skor SAW',
                     labels={'Skor SAW':'Nilai Preferensi'})
    fig_top.update_traces(texttemplate='%{text:.4f}', textposition='outside')
    st.plotly_chart(fig_top, use_container_width=True)

    with st.expander("Perbandingan Detail Top 5"):
        top5 = ranking.head(5)
        display_top5 = top5.copy()
        if 'price' in display_top5.columns:
            display_top5['price'] = display_top5['price'].apply(format_price_usd)
        st.dataframe(display_top5, column_config=column_config, use_container_width=True)

# ------------------------------
# FOOTER
# ------------------------------
st.sidebar.markdown("---")
st.sidebar.caption("Dibuat dengan Streamlit Kelompok 2")